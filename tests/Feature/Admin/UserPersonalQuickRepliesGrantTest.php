<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The fields the edit form always sends, plus the given ones.
 *
 * @return array<string, mixed>
 */
function personalGrantPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'role_id' => $user->role()?->id,
    ], $overrides);
}

test('an administrator grants and revokes personal quick replies to one user with audit entries', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();
    $reply = QuickReply::factory()->personal($agent)->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $agent), personalGrantPayload($agent, ['personal_quick_replies' => true]))
        ->assertSessionHasNoErrors();

    expect($agent->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::UserPersonalQuickRepliesGranted)->where('auditable_id', $agent->id)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $agent), personalGrantPayload($agent, ['personal_quick_replies' => false]))
        ->assertSessionHasNoErrors();

    expect($agent->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::UserPersonalQuickRepliesRevoked)->where('auditable_id', $agent->id)->exists())->toBeTrue()
        ->and($reply->fresh()->isDeleted())->toBeFalse();
});

test('a permission granted by the role is shown as such and cannot be revoked per user', function () {
    $admin = User::factory()->administrator()->create();
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::UseQuickReplies, Permission::CreatePersonalQuickReplies])->create();
    $agent = User::factory()->withRole($role)->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['search' => $agent->email]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.id', $agent->id)
            ->where('users.data.0.personal_quick_replies.granted_by_role', true)
            ->where('users.data.0.personal_quick_replies.granted_directly', false));

    $this->actingAs($admin)
        ->put(route('admin.users.update', $agent), personalGrantPayload($agent, ['personal_quick_replies' => false]))
        ->assertSessionHasErrors('personal_quick_replies');

    expect($agent->fresh()->holdsPermission(Permission::CreatePersonalQuickReplies))->toBeTrue();
});

test('changing the grant requires being allowed to assign roles to the user', function () {
    $editor = User::factory()->withRole(
        Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewUsers, Permission::UpdateUsers, Permission::CreatePersonalQuickReplies])->create(),
    )->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($editor)
        ->put(route('admin.users.update', $agent), personalGrantPayload($agent, ['role_id' => null, 'personal_quick_replies' => true]))
        ->assertForbidden();

    expect($agent->fresh()->hasDirectPermission(Permission::CreatePersonalQuickReplies->value))->toBeFalse();
});
