<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use Inertia\Testing\AssertableInertia as Assert;

/*
| "En espera" (on_hold) is retired (DESIGN decision 1): nothing leads into it,
| nothing offers it, old rows keep loading and counting as attention, and
| `conversations:retire-on-hold --apply` moves them back to attention (agent
| still able) or to their queue.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create(['name' => 'Ana']);
});

test('the hold and resume endpoints no longer exist', function (string $path) {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $response = $this->actingAs($this->agent)->postJson("/conversations/{$conversation->id}/{$path}", ['version' => 0]);

    expect($response->status())->toBeIn([404, 405])
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Open)
        ->and($conversation->events()->count())->toBe(0);
})->with(['hold', 'resume']);

test('no state leads into on_hold and it is never offered', function () {
    foreach (ConversationStatus::cases() as $status) {
        expect($status->canTransitionTo(ConversationStatus::OnHold))->toBeFalse();
    }

    expect(ConversationStatus::operational())->not->toContain(ConversationStatus::OnHold)
        ->and(array_column(ConversationStatus::options(), 'value'))->toBe(['pending', 'open', 'closed']);
});

test('a legacy on_hold conversation still loads, keeps its agent and counts as attention load', function () {
    $legacy = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->onHold('Esperando pago')->create();

    $this->actingAs($this->agent)
        ->get(route('conversations.index', ['c' => $legacy->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.status', 'on_hold')
            ->where('conversation.status_label', 'En espera (estado ya no usado)')
            ->missing('conversation.can.hold')
            ->missing('conversation.can.resume')
            ->missing('conversation.on_hold_reason'));

    expect(app(ConversationAccess::class)->attentionLoad($this->agent))->toBe(1);
});

test('the dry run lists what would change and writes nothing', function () {
    $legacy = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->onHold('Esperando pago')->create();

    $writes = databaseWritesDuring(fn () => $this->artisan('conversations:retire-on-hold')
        ->expectsOutputToContain('Simulación: 1 conversaciones en espera')
        ->expectsOutputToContain('Ana puede seguir atendiéndola.')
        ->assertSuccessful());

    expect($writes)->toBe([])
        ->and($legacy->refresh()->status)->toBe(ConversationStatus::OnHold)
        ->and($legacy->events()->count())->toBe(0);
});

test('apply returns the conversation to its agent when they can still attend it', function () {
    $legacy = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->onHold('Esperando pago')->create(['on_hold_at' => now()->subDay()]);

    $this->artisan('conversations:retire-on-hold', ['--apply' => true])->assertSuccessful();

    $event = $legacy->events()->sole();

    expect($legacy->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id)
        ->on_hold_at->toBeNull()
        ->on_hold_reason->toBeNull()
        ->version->toBe(1)
        ->and($event->type)->toBe(ConversationEventType::Resumed)
        ->and($event->actor_user_id)->toBeNull()
        ->and($event->metadata)->toMatchArray(['reason' => 'on_hold_retired', 'on_hold_reason' => 'Esperando pago'])
        ->and($legacy->notes()->sole()->body)->toContain('Esperando pago')
        ->and(AuditLog::query()->where('action', AuditAction::ConversationOnHoldRetired)->count())->toBe(1);
});

test('apply queues the conversation when its agent can no longer attend it, through the normal distribution', function () {
    $department = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $gone = User::factory()->agent()->inactive()->inDepartments($department)->create();
    $colleague = User::factory()->agent()->inDepartments($department)->create();
    $legacy = Conversation::factory()->inDepartment($department)->assignedTo($gone)->onHold()->create();
    $unassigned = Conversation::factory()->inDepartment($this->department)->onHold()->create(['assigned_user_id' => null]);

    $this->artisan('conversations:retire-on-hold', ['--apply' => true])->assertSuccessful();

    expect($legacy->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($colleague->id)
        ->and($legacy->events()->orderBy('id')->pluck('type')->all())->toBe([ConversationEventType::Unassigned, ConversationEventType::AutoAssigned])
        ->and($unassigned->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->and(Conversation::query()->where('status', ConversationStatus::OnHold)->count())->toBe(0);

    $this->artisan('conversations:retire-on-hold', ['--apply' => true])
        ->expectsOutputToContain('No hay conversaciones en espera')
        ->assertSuccessful();
});

test('dry run and apply cannot be combined', function () {
    $this->artisan('conversations:retire-on-hold', ['--apply' => true, '--dry-run' => true])->assertFailed();
});

test('apply offers the freed agent the waiting conversations of their other automatic queues', function () {
    $automatic = Department::factory()->distribution(DistributionMode::Automatic)->create();
    $closedDown = Department::factory()->inactive()->create();
    $agent = User::factory()->agent()->inDepartments([$automatic, $closedDown])->create(['max_open_conversations' => 1]);
    $legacy = Conversation::factory()->inDepartment($closedDown)->assignedTo($agent)->onHold()->create();
    $waiting = Conversation::factory()->inDepartment($automatic)->create();

    $this->artisan('conversations:retire-on-hold', ['--apply' => true])->assertSuccessful();

    expect($legacy->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->and($waiting->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($agent->id);
});
