<?php

use App\Actions\Users\ChangeUserStatus;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('administrators can deactivate a user', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.status.update', $agent), ['is_active' => false])
        ->assertSessionHasNoErrors();

    $agent->refresh();

    expect($agent->is_active)->toBeFalse()
        ->and($agent->deactivated_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::UserDeactivated)->where('auditable_id', $agent->id)->exists())->toBeTrue();
});

test('deactivating an agent returns their open conversations to the department queue', function () {
    $admin = User::factory()->administrator()->create();
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $open = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create();
    $closed = Conversation::factory()->inDepartment($department)->assignedTo($agent)->closed()->create();

    $this->actingAs($admin)->patch(route('admin.users.status.update', $agent), ['is_active' => false]);

    expect($open->refresh())
        ->assigned_user_id->toBeNull()
        ->status->toBe(ConversationStatus::Pending)
        ->version->toBe(1)
        ->and($open->events()->sole()->type)->toBe(ConversationEventType::Unassigned)
        ->and($closed->refresh()->assigned_user_id)->toBe($agent->id);
});

test('administrators can reactivate a user', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->inactive()->create();

    $this->actingAs($admin)->patch(route('admin.users.status.update', $agent), ['is_active' => true]);

    expect($agent->refresh())
        ->is_active->toBeTrue()
        ->deactivated_at->toBeNull();
});

test('administrators cannot deactivate their own account', function () {
    $admin = User::factory()->administrator()->create();
    User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->patch(route('admin.users.status.update', $admin), ['is_active' => false])
        ->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('non administrators cannot change the status of a user', function () {
    $supervisor = User::factory()->supervisor()->create();
    $agent = User::factory()->agent()->create();

    $this->actingAs($supervisor)
        ->patch(route('admin.users.status.update', $agent), ['is_active' => false])
        ->assertForbidden();

    expect($agent->refresh()->is_active)->toBeTrue();
});

test('the last active administrator can never be deactivated', function () {
    $lastAdministrator = User::factory()->administrator()->create();
    User::factory()->administrator()->inactive()->create();
    $actor = User::factory()->supervisor()->create();

    expect(fn () => app(ChangeUserStatus::class)->handle($lastAdministrator, false, $actor))
        ->toThrow(ValidationException::class, 'Es la última autoridad administrativa activa');

    expect($lastAdministrator->refresh()->is_active)->toBeTrue();
});
