<?php

use App\Actions\Users\DeleteUser;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An organization-wide user manager holding exactly the given extra permissions.
 *
 * @param  list<Permission>  $extra
 */
function userManager(array $extra = []): User
{
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([
        Permission::ViewDashboard, Permission::ViewUsers, Permission::UpdateUsers, Permission::AssignRoles, ...$extra,
    ])->create();

    return User::factory()->withRole($role)->create();
}

test('deleting a user blocks access, discards sessions and keeps the account and its history', function () {
    config(['session.driver' => 'database']);
    $admin = User::factory()->superAdministrator()->create();
    $agent = User::factory()->agent()->create(['remember_token' => 'token']);
    $message = Message::factory()->create(['sender_user_id' => $agent->id]);
    DB::table('sessions')->insert(['id' => 'agent-session', 'user_id' => $agent->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $agent))
        ->assertSessionHasNoErrors();

    $this->assertModelExists($agent);
    expect($agent->refresh())
        ->isDeleted()->toBeTrue()
        ->deactivated_at->not->toBeNull()
        ->remember_token->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $agent->id)->exists())->toBeFalse()
        ->and($message->refresh()->sender_user_id)->toBe($agent->id)
        ->and(AuditLog::query()->where('action', AuditAction::UserDeleted)->where('auditable_id', $agent->id)->exists())->toBeTrue();

    $this->actingAs($agent)->get(route('dashboard'))->assertRedirect(route('login'));
});

test('deleting an agent returns their open conversations to the department queue with an audited event', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $open = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $closed = Conversation::factory()->inDepartment($department)->assignedTo($agent)->closed()->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $agent))->assertSessionHasNoErrors();

    $event = $open->events()->sole();

    expect($open->refresh())
        ->assigned_user_id->toBeNull()
        ->department_id->toBe($department->id)
        ->status->toBe(ConversationStatus::Pending)
        ->and($event->type)->toBe(ConversationEventType::Unassigned)
        ->and($event->from_user_id)->toBe($agent->id)
        ->and($event->actor_user_id)->toBe($admin->id)
        ->and($closed->refresh()->assigned_user_id)->toBe($agent->id)
        ->and(AuditLog::query()->where('action', AuditAction::UserDeleted)->sole()->properties)->toMatchArray(['released_conversations' => 1]);
});

test('restoring a user lets them sign in again without returning conversations', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $agent));
    $this->actingAs($admin)->patch(route('admin.users.restore', $agent))->assertSessionHasNoErrors();

    expect($agent->refresh())
        ->is_active->toBeTrue()
        ->deactivated_at->toBeNull()
        ->and($conversation->refresh()->assigned_user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::UserRestored)->where('auditable_id', $agent->id)->exists())->toBeTrue();
});

test('nobody can delete their own account', function () {
    $admin = User::factory()->superAdministrator()->create();
    User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('deleting and restoring require their permissions', function () {
    $agent = User::factory()->agent()->create();
    $deleted = User::factory()->withRole(Role::factory()->withPermissions([Permission::ViewDashboard])->create())->inactive()->create();

    $this->actingAs(User::factory()->supervisor()->create())
        ->delete(route('admin.users.destroy', $agent))
        ->assertForbidden();

    $withoutViewDeleted = userManager([Permission::DeleteUsers]);

    $this->actingAs($withoutViewDeleted)->patch(route('admin.users.restore', $deleted))->assertForbidden();
    $this->actingAs(userManager([Permission::ViewDeletedRecords]))->patch(route('admin.users.restore', $deleted))->assertForbidden();
    $this->actingAs(userManager([Permission::DeleteUsers, Permission::ViewDeletedRecords]))->patch(route('admin.users.restore', $deleted))->assertSessionHasNoErrors();

    expect($agent->refresh()->is_active)->toBeTrue()
        ->and($deleted->refresh()->is_active)->toBeTrue();
});

test('deleted users are invisible in lists, filters and counters without records.view-deleted', function () {
    $manager = userManager([Permission::DeleteUsers]);
    User::factory()->agent()->create(['name' => 'Laura Activa']);
    User::factory()->agent()->inactive()->create(['name' => 'Laura Eliminada']);

    foreach (['deleted', 'all'] as $record) {
        $this->actingAs($manager)
            ->get(route('admin.users.index', ['search' => 'laura', 'record' => $record]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Laura Activa')
                ->where('filters.record', 'active')
                ->where('recordStates', [])
                ->where('stats.active', 2)
                ->where('stats.deleted', null));
    }
});

test('people who can consult deleted records filter them and see restore actions', function () {
    $admin = User::factory()->superAdministrator()->create();
    User::factory()->agent()->create(['name' => 'Laura Activa']);
    User::factory()->agent()->inactive()->create(['name' => 'Laura Eliminada']);

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => 'laura', 'record' => 'deleted']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Laura Eliminada')
            ->where('users.data.0.is_deleted', true)
            ->where('users.data.0.can.restore', true)
            ->where('users.data.0.can.delete', false)
            ->where('users.data.0.can.update', false)
            ->where('stats.deleted', 1)
            ->has('recordStates', 3));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => 'laura', 'record' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 2));
});

test('a deleted account cannot be edited until it is restored', function () {
    $admin = User::factory()->superAdministrator()->create();
    $deleted = User::factory()->agent()->inactive()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $deleted), ['name' => 'Otro nombre', 'email' => $deleted->email])
        ->assertForbidden();
});

test('an administrator never deletes, restores or edits a superadministrator', function () {
    $administrator = User::factory()->administrator()->create();
    $root = User::factory()->superAdministrator()->create();
    $deletedRoot = User::factory()->superAdministrator()->inactive()->create();

    $this->actingAs($administrator)->delete(route('admin.users.destroy', $root))->assertForbidden();
    $this->actingAs($administrator)->patch(route('admin.users.restore', $deletedRoot))->assertForbidden();
    $this->actingAs($administrator)->put(route('admin.users.update', $root), ['name' => 'X', 'email' => $root->email])->assertForbidden();

    expect($root->refresh()->is_active)->toBeTrue()
        ->and($deletedRoot->refresh()->is_active)->toBeFalse();
});

test('the last active superadministrator can never be deleted', function () {
    $lastAdministrator = User::factory()->superAdministrator()->create();
    User::factory()->superAdministrator()->inactive()->create();
    $actor = User::factory()->supervisor()->create();

    expect(fn () => app(DeleteUser::class)->handle($lastAdministrator, $actor))
        ->toThrow(ValidationException::class, 'Es el último superadministrador activo');

    expect($lastAdministrator->refresh()->is_active)->toBeTrue();
});
