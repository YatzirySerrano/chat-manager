<?php

use App\Enums\ConversationReadiness;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Enums\ResponseCycleKind;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\ResponseCycle;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function serviceRulePermissionsOf(Role $role): array
{
    return DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_id', $role->id)
        ->whereIn('permissions.name', ['categories.manage', 'conversations.confirm-ready', 'conversations.override-queue-rules', 'attention.view'])
        ->orderBy('permissions.name')
        ->pluck('permissions.name')
        ->all();
}

test('a conversation written without the new columns is ready and outside any category', function () {
    $id = DB::table('conversations')->insertGetId([
        'connection_id' => Connection::factory()->create()->id,
        'contact_id' => Contact::factory()->create()->id,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $conversation = Conversation::query()->findOrFail($id);

    expect($conversation->readiness)->toBe(ConversationReadiness::Ready)
        ->and($conversation->readiness_changed_at)->toBeNull()
        ->and($conversation->category_id)->toBeNull()
        ->and($conversation->queued_at)->toBeNull()
        ->and((new Conversation)->readiness)->toBe(ConversationReadiness::Ready)
        ->and(ConversationReadiness::NeedsInfo->label())->toBe('Necesita información');
});

test('a conversation keeps its category and the category lists its conversations', function () {
    $category = ConversationCategory::factory()->create();
    $conversation = Conversation::factory()->create(['category_id' => $category->id]);

    expect($conversation->category->is($category))->toBeTrue()
        ->and($category->conversations()->pluck('id')->all())->toBe([$conversation->id]);

    expect(fn () => DB::table('conversations')->where('id', $conversation->id)->update(['category_id' => 999_999]))
        ->toThrow(QueryException::class, 'foreign key constraint fails');
});

test('the database allows a single open response cycle per conversation', function () {
    $conversation = Conversation::factory()->inDepartment(Department::factory()->create())->create();
    $first = ResponseCycle::factory()->for($conversation)->answered()->create();
    $second = ResponseCycle::factory()->for($conversation)->closedUnanswered()->create(['kind' => ResponseCycleKind::Response]);
    $open = ResponseCycle::factory()->for($conversation)->withTarget(600)->create(['kind' => ResponseCycleKind::Response]);

    expect(fn () => ResponseCycle::factory()->for($conversation)->create())->toThrow(UniqueConstraintViolationException::class);

    expect($conversation->responseCycles()->active()->pluck('id')->all())->toBe([$open->id])
        ->and($open->refresh()->isActive())->toBeTrue()
        ->and($open->department_id)->toBe($conversation->department_id)
        ->and($open->due_at->equalTo($open->started_at->addSeconds(600)))->toBeTrue()
        ->and($open->warn_at->equalTo($open->started_at->addSeconds(480)))->toBeTrue()
        ->and($first->refresh()->isActive())->toBeFalse()
        ->and($second->refresh()->outcome)->toBe(ResponseCycle::OUTCOME_CLOSED);
});

test('breached cycles are found whether they are still open or already ended', function () {
    $open = ResponseCycle::factory()->withTarget(60)->breached()->create();
    $ended = ResponseCycle::factory()->withTarget(60)->breached()->answered(afterSeconds: 90)->create();
    ResponseCycle::factory()->withTarget(60)->create();

    expect(ResponseCycle::query()->breached()->orderBy('id')->pluck('id')->all())->toBe([$open->id, $ended->id])
        ->and(ResponseCycle::query()->breached()->active()->pluck('id')->all())->toBe([$open->id]);
});

test('the service rule permissions are granted to the root and administrator roles only, and it can be reversed', function () {
    $new = ['categories.manage', 'conversations.confirm-ready', 'conversations.override-queue-rules'];
    DB::table('permissions')->whereIn('name', $new)->delete();
    $custom = Role::factory()->withPermissions([Permission::ViewConversations])->create();

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_10_193956_add_service_rules_permissions.php');
    $migration->up();
    $migration->up();

    expect(serviceRulePermissionsOf(role(DefaultRole::SuperAdministrator)))->toContain(...$new)
        ->and(serviceRulePermissionsOf(role(DefaultRole::Administrator)))->toContain(...$new)
        ->and(serviceRulePermissionsOf(role(DefaultRole::Agent)))->toBe([])
        ->and(serviceRulePermissionsOf($custom))->toBe([]);

    $migration->down();

    expect(DB::table('permissions')->whereIn('name', $new)->exists())->toBeFalse();
});

test('a new installation gives supervisors the confirmation, the exceptions and the attention tracking, and agents none', function () {
    expect(serviceRulePermissionsOf(role(DefaultRole::Supervisor)))->toBe(['attention.view', 'conversations.confirm-ready', 'conversations.override-queue-rules'])
        ->and(serviceRulePermissionsOf(role(DefaultRole::Agent)))->toBe([])
        ->and(serviceRulePermissionsOf(role(DefaultRole::Administrator)))->toBe(['attention.view', 'categories.manage', 'conversations.confirm-ready', 'conversations.override-queue-rules'])
        ->and(Permission::OverrideQueueRules->isSensitive())->toBeTrue()
        ->and(Permission::ManageCategories->requiresGlobalScope())->toBeTrue();
});
