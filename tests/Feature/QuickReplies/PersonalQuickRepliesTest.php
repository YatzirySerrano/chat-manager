<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A user of the given scope holding the given permissions.
 *
 * @param  list<Permission>  $permissions
 * @param  list<Department>  $departments
 */
function personalRepliesUser(array $permissions, DataScope $scope = DataScope::Assigned, array $departments = []): User
{
    $role = Role::factory()->scope($scope)->withPermissions($permissions)->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create();
}

/**
 * An agent that uses replies and may create personal ones.
 *
 * @param  list<Department>  $departments
 */
function personalRepliesAgent(array $departments = []): User
{
    return personalRepliesUser([Permission::UseQuickReplies, Permission::CreatePersonalQuickReplies], DataScope::Assigned, $departments);
}

/**
 * @return array<string, mixed>
 */
function personalReplyInput(array $overrides = []): array
{
    return array_merge([
        'type' => 'personal',
        'title' => 'Mi saludo',
        'shortcut' => 'misaludo',
        'body' => 'Hola, soy {agente.nombre}.',
    ], $overrides);
}

test('a user without the personal permission cannot create a personal reply', function () {
    $agent = personalRepliesUser([Permission::UseQuickReplies]);

    $this->actingAs($agent)
        ->post(route('quick-replies.store'), personalReplyInput())
        ->assertForbidden();

    expect(QuickReply::query()->count())->toBe(0);
});

test('a user with the personal permission creates, edits and deletes their own replies', function () {
    $agent = personalRepliesAgent();

    $this->actingAs($agent)
        ->post(route('quick-replies.store'), personalReplyInput(['is_global' => true]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $reply = QuickReply::query()->sole();

    expect($reply->owner_user_id)->toBe($agent->id)
        ->and($reply->is_global)->toBeFalse()
        ->and($reply->departments()->count())->toBe(0);

    $this->actingAs($agent)
        ->put(route('quick-replies.update', $reply), personalReplyInput(['title' => 'Saludo propio', 'type' => 'corporate', 'is_global' => true]))
        ->assertSessionHasNoErrors();

    $reply->refresh();

    expect($reply->title)->toBe('Saludo propio')
        ->and($reply->isPersonal())->toBeTrue()
        ->and($reply->is_global)->toBeFalse();

    $this->actingAs($agent)
        ->delete(route('quick-replies.destroy', $reply))
        ->assertRedirect();

    expect($reply->refresh()->isDeleted())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::QuickReplyDeleted)->exists())->toBeTrue();
});

test('personal shortcuts are unique per owner but may repeat corporate or other users shortcuts', function () {
    $agent = personalRepliesAgent();
    QuickReply::factory()->create(['shortcut' => 'misaludo']);
    QuickReply::factory()->personal(personalRepliesAgent())->create(['shortcut' => 'misaludo']);

    $this->actingAs($agent)
        ->post(route('quick-replies.store'), personalReplyInput())
        ->assertSessionHasNoErrors();

    $this->actingAs($agent)
        ->post(route('quick-replies.store'), personalReplyInput(['title' => 'Otro']))
        ->assertSessionHasErrors('shortcut');

    expect(QuickReply::query()->personal($agent)->count())->toBe(1);
});

test('after revoking the permission the owner keeps using the replies but cannot change them', function () {
    $agent = personalRepliesAgent();
    $reply = QuickReply::factory()->personal($agent)->create(['shortcut' => 'propia']);
    $agent->role()->revokePermissionTo(Permission::CreatePersonalQuickReplies->value);
    $agent->refresh();

    $this->actingAs($agent)
        ->put(route('quick-replies.update', $reply), personalReplyInput(['shortcut' => 'propia']))
        ->assertForbidden();

    $this->actingAs($agent)->delete(route('quick-replies.destroy', $reply))->assertForbidden();

    $this->actingAs($agent)
        ->post(route('quick-replies.store'), personalReplyInput(['shortcut' => 'nueva']))
        ->assertForbidden();

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $reply->id)
        ->assertJsonPath('data.0.is_personal', true);

    $this->actingAs($agent)
        ->get(route('quick-replies.index', ['segment' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.segment', 'mine')
            ->where('quickReplies.data.0.id', $reply->id)
            ->where('quickReplies.data.0.can.update', false)
            ->where('can.createPersonal', false));

    expect($reply->refresh()->isDeleted())->toBeFalse();
});

test('another user never sees, uses or changes a personal reply', function () {
    $support = Department::factory()->create();
    $owner = personalRepliesAgent([$support]);
    $reply = QuickReply::factory()->personal($owner)->create(['shortcut' => 'secreta', 'title' => 'Secreta']);
    $colleague = personalRepliesUser(
        [Permission::UseQuickReplies, Permission::ManageQuickReplies, Permission::CreatePersonalQuickReplies],
        DataScope::Departments,
        [$support],
    );
    $globalAdmin = personalRepliesUser([Permission::UseQuickReplies, Permission::ManageQuickReplies], DataScope::Global);

    foreach ([$colleague, $globalAdmin] as $viewer) {
        foreach (['corporate', 'mine', 'others'] as $segment) {
            $this->actingAs($viewer)
                ->get(route('quick-replies.index', ['segment' => $segment]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('quickReplies.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($reply->id)));
        }

        $this->actingAs($viewer)
            ->getJson(route('quick-replies.available', ['q' => 'secreta', 'department_id' => $support->id]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($viewer)
            ->put(route('quick-replies.update', $reply), personalReplyInput(['shortcut' => 'secreta']))
            ->assertNotFound();

        $this->actingAs($viewer)->delete(route('quick-replies.destroy', $reply))->assertNotFound();
        $this->actingAs($viewer)->patch(route('quick-replies.restore', $reply))->assertNotFound();
    }

    expect($reply->refresh()->title)->toBe('Secreta')->and($reply->isDeleted())->toBeFalse();
});

test('the picker lists the own personal replies first and flags them', function () {
    $agent = personalRepliesAgent();
    QuickReply::factory()->create(['shortcut' => 'aaa-corporativa']);
    $own = QuickReply::factory()->personal($agent)->create(['shortcut' => 'zzz-propia']);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.is_personal', true)
        ->assertJsonPath('data.1.is_personal', false);
});

test('a supervisor with view-personal lists personal replies of their scope with filters', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $ana = personalRepliesAgent([$sales]);
    $bruno = personalRepliesAgent([$support]);
    $anaReply = QuickReply::factory()->personal($ana)->create(['body' => 'Texto de Ana']);
    $brunoReply = QuickReply::factory()->personal($bruno)->create();
    $admin = personalRepliesUser([Permission::UseQuickReplies, Permission::ViewPersonalItems], DataScope::Global);
    QuickReply::factory()->personal($admin)->create();

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['segment' => 'others']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.segment', 'others')
            ->has('quickReplies.data', 2)
            ->has('owners', 2)
            ->where('segments', fn ($segments) => collect($segments)->pluck('value')->all() === ['corporate', 'mine', 'others']));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['segment' => 'others', 'owner' => $ana->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickReplies.data', 1)
            ->where('quickReplies.data.0.id', $anaReply->id)
            ->where('quickReplies.data.0.body', 'Texto de Ana')
            ->where('quickReplies.data.0.owner.name', $ana->name)
            ->where('quickReplies.data.0.can.update', false));

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['segment' => 'others', 'department' => $support->id]))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 1)->where('quickReplies.data.0.id', $brunoReply->id));

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $anaReply), personalReplyInput(['shortcut' => $anaReply->shortcut]))
        ->assertForbidden();

    $this->actingAs($admin)->delete(route('quick-replies.destroy', $anaReply))->assertForbidden();
});

test('a supervisor with manage-personal edits and deletes them with an audit entry without the text', function () {
    $owner = personalRepliesAgent();
    $reply = QuickReply::factory()->personal($owner)->create(['title' => 'Original', 'body' => 'Texto privado']);
    // Managing the owner's account requires holding every permission of their role.
    $admin = personalRepliesUser([Permission::UseQuickReplies, Permission::CreatePersonalQuickReplies, Permission::ViewPersonalItems, Permission::ManagePersonalItems], DataScope::Global);

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $reply), personalReplyInput(['title' => 'Corregida', 'shortcut' => $reply->shortcut, 'body' => 'Texto nuevo']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($reply->refresh()->title)->toBe('Corregida')->and($reply->owner_user_id)->toBe($owner->id);

    $updated = AuditLog::query()->where('action', AuditAction::SupervisionPersonalItemUpdated)->sole();

    expect($updated->properties)->toMatchArray([
        'owner_user_id' => $owner->id,
        'item_type' => 'quick_reply',
        'item_id' => $reply->id,
        'title' => 'Corregida',
    ])->and(json_encode($updated->properties))->not->toContain('Texto');

    $this->actingAs($admin)->delete(route('quick-replies.destroy', $reply))->assertRedirect();

    $deleted = AuditLog::query()->where('action', AuditAction::SupervisionPersonalItemDeleted)->sole();

    expect($reply->refresh()->isDeleted())->toBeTrue()
        ->and($deleted->properties)->toBe([
            'owner_user_id' => $owner->id,
            'item_type' => 'quick_reply',
            'item_id' => $reply->id,
            'title' => 'Corregida',
        ]);
});

test('a departments-scoped supervisor only reaches users sharing one of their departments', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $inside = QuickReply::factory()->personal(personalRepliesAgent([$sales]))->create();
    $outside = QuickReply::factory()->personal(personalRepliesAgent([$support]))->create();
    $supervisor = personalRepliesUser(
        [Permission::UseQuickReplies, Permission::ViewPersonalItems, Permission::ManagePersonalItems],
        DataScope::Departments,
        [$sales],
    );

    $this->actingAs($supervisor)
        ->get(route('quick-replies.index', ['segment' => 'others']))
        ->assertInertia(fn (Assert $page) => $page->has('quickReplies.data', 1)->where('quickReplies.data.0.id', $inside->id));

    $this->actingAs($supervisor)
        ->put(route('quick-replies.update', $outside), personalReplyInput(['shortcut' => $outside->shortcut]))
        ->assertNotFound();

    $this->actingAs($supervisor)
        ->getJson(route('quick-replies.available'))
        ->assertJsonCount(0, 'data');
});

test('an assigned-scope user with view-personal does not get the supervision segment', function () {
    QuickReply::factory()->personal(personalRepliesAgent())->create();
    $viewer = personalRepliesUser([Permission::UseQuickReplies, Permission::ViewPersonalItems]);

    $this->actingAs($viewer)
        ->get(route('quick-replies.index', ['segment' => 'others']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.segment', 'corporate')
            ->where('segments', fn ($segments) => collect($segments)->pluck('value')->all() === ['corporate']));
});

test('the picker of a supervisor of personal replies offers only their own personal replies', function (array $supervision) {
    $ana = personalRepliesAgent();
    QuickReply::factory()->personal($ana)->create(['title' => 'Saludo de Ana']);
    $admin = personalRepliesUser([Permission::UseQuickReplies, Permission::CreatePersonalQuickReplies, ...$supervision], DataScope::Global);
    $own = QuickReply::factory()->personal($admin)->create(['title' => 'Saludo propio']);

    $this->actingAs($admin)
        ->getJson(route('quick-replies.available', ['q' => 'Saludo']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
})->with([
    'view-personal' => [[Permission::ViewPersonalItems]],
    'view and manage personal' => [[Permission::ViewPersonalItems, Permission::ManagePersonalItems]],
]);

test('a departments-scoped supervisor filtering by an owner outside their departments gets nothing', function () {
    [$sales, $support] = Department::factory()->count(2)->create();
    $outsider = personalRepliesAgent([$support]);
    QuickReply::factory()->personal($outsider)->create();
    QuickReply::factory()->personal(personalRepliesAgent([$sales]))->create();
    $supervisor = personalRepliesUser([Permission::UseQuickReplies, Permission::ViewPersonalItems], DataScope::Departments, [$sales]);

    $this->actingAs($supervisor)
        ->get(route('quick-replies.index', ['segment' => 'others', 'owner' => $outsider->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quickReplies.data', 0)
            ->where('owners', fn ($owners) => ! collect($owners)->contains('id', $outsider->id)));
});

test('personal replies of a superadministrator stay out of reach of non-root supervisors', function () {
    $root = User::factory()->superAdministrator()->create();
    $rootReply = QuickReply::factory()->personal($root)->create(['body' => 'Texto del superadministrador']);
    $agentReply = QuickReply::factory()->personal(personalRepliesAgent())->create();
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('quick-replies.index', ['segment' => 'others']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('quickReplies.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$agentReply->id])
            ->where('owners', fn ($owners) => ! collect($owners)->contains('id', $root->id)));

    $this->actingAs($admin)
        ->put(route('quick-replies.update', $rootReply), personalReplyInput(['shortcut' => $rootReply->shortcut]))
        ->assertNotFound();
});
