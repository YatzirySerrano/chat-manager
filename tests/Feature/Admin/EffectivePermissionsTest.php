<?php

use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Users\UserAccessChanged;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->root = User::factory()->superAdministrator()->create();
});

/**
 * Form payload of a role with the given permissions.
 *
 * @param  list<Permission>  $permissions
 * @return array<string, mixed>
 */
function rolePayload(Role $role, array $permissions, ?DataScope $scope = null): array
{
    return [
        'label' => $role->label,
        'description' => $role->description,
        'scope' => ($scope ?? $role->scope)->value,
        'permissions' => array_map(fn (Permission $permission): string => $permission->value, $permissions),
    ];
}

/**
 * The toast flashed by the response.
 *
 * @return array{type?: string, message?: string}
 */
function flashedToast(TestResponse $response): array
{
    $response->assertRedirect();

    return session()->get(SessionKey::FLASH_DATA, [])['toast'] ?? [];
}

/**
 * The agent permissions without the given ones.
 *
 * @return list<Permission>
 */
function agentPermissionsWithout(Permission ...$removed): array
{
    return array_values(array_filter(DefaultRole::Agent->permissions(), fn (Permission $permission): bool => ! in_array($permission, $removed, true)));
}

test('revoking transfer from the role an agent holds blocks transfers on the next request and granting it back restores them', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions(DefaultRole::Agent->permissions())->create(['label' => 'Demo B · Agente']);
    $agent = User::factory()->withRole($role)->inDepartments($sales)->create();
    $conversation = Conversation::factory()->inDepartment($sales)->assignedTo($agent)->create();

    $toast = flashedToast($this->actingAs($this->root)
        ->put(route('admin.roles.update', $role), rolePayload($role, agentPermissionsWithout(Permission::TransferConversations)))
        ->assertSessionHasNoErrors());

    expect($toast['message'] ?? '')->toContain('1 usuario activo');

    $this->actingAs($agent->fresh())
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions): bool => ! collect($permissions)->contains(Permission::TransferConversations->value))
            ->where('conversation.can.transfer', false));

    $this->actingAs($agent->fresh())
        ->post(route('conversations.transfer', $conversation), ['department_id' => $support->id, 'version' => $conversation->version])
        ->assertForbidden();

    expect($conversation->refresh()->department_id)->toBe($sales->id);

    $this->actingAs($this->root)
        ->put(route('admin.roles.update', $role), rolePayload($role, DefaultRole::Agent->permissions()))
        ->assertSessionHasNoErrors();

    $this->actingAs($agent->fresh())
        ->get(route('conversations.index', ['c' => $conversation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('conversation.can.transfer', true));

    $this->actingAs($agent->fresh())
        ->post(route('conversations.transfer', $conversation), ['department_id' => $support->id, 'version' => $conversation->refresh()->version])
        ->assertSessionHasNoErrors();

    expect($conversation->refresh()->department_id)->toBe($support->id);
});

test('saving a role says how many active users the change reaches and warns when nobody holds it', function () {
    $unused = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create();

    $toast = flashedToast($this->actingAs($this->root)
        ->put(route('admin.roles.update', $unused), rolePayload($unused, [Permission::ViewConversations])));

    expect($toast['type'] ?? null)->toBe('warning')
        ->and($toast['message'] ?? '')->toContain('no está asignado a ningún usuario');

    $used = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::ReplyConversations])->create();
    User::factory()->withRole($used)->count(2)->create();
    User::factory()->withRole($used)->inactive()->create();

    $toast = flashedToast($this->actingAs($this->root)
        ->put(route('admin.roles.update', $used), rolePayload($used, [Permission::ViewConversations])));

    expect($toast['type'] ?? null)->toBe('success')
        ->and($toast['message'] ?? '')->toContain('2 usuarios activos');
});

test('the roles screen tells beforehand how many active users hold each role', function () {
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations])->create(['label' => 'Contado']);
    User::factory()->withRole($role)->count(2)->create();
    User::factory()->withRole($role)->inactive()->create();

    $this->actingAs($this->root)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'roles',
            fn ($roles): bool => collect($roles)->firstWhere('label', 'Contado')['active_users_count'] === 2,
        ));
});

test('holders who keep a revoked permission through a direct grant are reported before and after saving', function () {
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::UseQuickReplies, Permission::CreatePersonalQuickReplies])->create(['label' => 'Con personales']);
    $keeps = User::factory()->withRole($role)->create();
    $keeps->givePermissionTo(Permission::CreatePersonalQuickReplies->value);
    $loses = User::factory()->withRole($role)->create();

    $this->actingAs($this->root)
        ->get(route('admin.roles.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'roles',
            fn ($roles): bool => collect($roles)->firstWhere('label', 'Con personales')['retained_elsewhere'] === [Permission::CreatePersonalQuickReplies->value => 1],
        ));

    $toast = flashedToast($this->actingAs($this->root)
        ->put(route('admin.roles.update', $role), rolePayload($role, [Permission::UseQuickReplies])));

    expect($toast['type'] ?? null)->toBe('warning')
        ->and($toast['message'] ?? '')->toContain('1 usuario conserva');

    expect($keeps->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeTrue()
        ->and($loses->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeFalse();
});

test('a user holding two roles keeps a permission revoked from one of them and the save says so', function () {
    $first = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::TransferConversations])->create();
    $second = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::TransferConversations])->create(['label' => 'Segundo rol']);
    $user = User::factory()->withRole($first)->create();
    $user->roles()->attach($second->id);

    $toast = flashedToast($this->actingAs($this->root)
        ->put(route('admin.roles.update', $first), rolePayload($first, [Permission::ViewConversations])));

    expect($toast['message'] ?? '')->toContain('1 usuario conserva');

    expect($user->fresh()->holdsPermission(Permission::TransferConversations))->toBeTrue();

    $this->actingAs($this->root)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'users.data',
            fn ($users): bool => collect(collect($users)->firstWhere('id', $user->id)['access']['extra_grants'])
                ->contains(fn (array $grant): bool => $grant['value'] === Permission::TransferConversations->value && $grant['sources'] === ['Segundo rol']),
        ));
});

test('the user list explains which permissions come from a direct grant', function () {
    $agent = User::factory()->agent()->create();
    $agent->givePermissionTo(Permission::CreatePersonalQuickReplies->value);

    $this->actingAs($this->root)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'users.data',
            fn ($users): bool => collect($users)->firstWhere('id', $agent->id)['access'] === [
                'effective_count' => count(DefaultRole::Agent->permissions()) + 1,
                'extra_grants' => [[
                    'value' => Permission::CreatePersonalQuickReplies->value,
                    'label' => Permission::CreatePersonalQuickReplies->label(),
                    'sources' => ['Asignación directa'],
                ]],
            ],
        ));
});

test('revoking an administrative permission blocks its endpoints at once', function () {
    $managerRole = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewUsers, Permission::UpdateUsers])->create();
    $manager = User::factory()->withRole($managerRole)->create();
    $agent = User::factory()->withRole(Role::factory()->scope(DataScope::Assigned)->create())->create();

    $this->actingAs($manager->fresh())
        ->put(route('admin.users.update', $agent), ['name' => 'Renombrado', 'email' => $agent->email])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->root)
        ->put(route('admin.roles.update', $managerRole), rolePayload($managerRole, [Permission::ViewUsers]))
        ->assertSessionHasNoErrors();

    $this->actingAs($manager->fresh())
        ->put(route('admin.users.update', $agent), ['name' => 'Otra vez', 'email' => $agent->email])
        ->assertForbidden();

    $this->actingAs($this->root)
        ->put(route('admin.roles.update', $managerRole), rolePayload($managerRole, []))
        ->assertSessionHasNoErrors();

    $this->actingAs($manager->fresh())->get(route('admin.users.index'))->assertForbidden();

    expect($agent->refresh()->name)->toBe('Renombrado');
});

test('role permission and scope changes signal exactly the holders of the role', function () {
    config(['broadcasting.default' => 'log']);
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewConversations])->create();
    $holders = User::factory()->withRole($role)->count(2)->create();
    User::factory()->agent()->create();
    Event::fake([RealtimeSignal::class]);

    $this->actingAs($this->root)
        ->put(route('admin.roles.update', $role), rolePayload($role, [Permission::ViewConversations], DataScope::Assigned))
        ->assertSessionHasNoErrors();

    $channels = Event::dispatched(RealtimeSignal::class)
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->where('event', 'access.changed')
        ->flatMap(fn (RealtimeSignal $signal): array => $signal->channels)
        ->all();

    expect($channels)->toEqualCanonicalizing($holders->map(fn (User $user): string => "users.{$user->id}")->all());
});

test('granting or revoking a direct permission revalidates that user only', function () {
    $agent = User::factory()->agent()->create();
    User::factory()->agent()->create();
    Event::fake([UserAccessChanged::class]);

    $this->actingAs($this->root)
        ->put(route('admin.users.update', $agent), ['name' => $agent->name, 'email' => $agent->email, 'personal_quick_replies' => true])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->root)
        ->put(route('admin.users.update', $agent), ['name' => $agent->name, 'email' => $agent->email, 'personal_quick_replies' => false])
        ->assertSessionHasNoErrors();

    Event::assertDispatchedTimes(UserAccessChanged::class, 2);
    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id] && $event->reason === 'permissions_changed');
    expect($agent->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeFalse();
});
