<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
| «Confirmar solicitud completa» / «Marcar que necesita información»:
| readiness is a preparation state, independent of the conversation status.
| It needs conversations.confirm-ready over a conversation within the
| operational reach, is idempotent, and is recorded as an event and in the
| audit log with the previous and the new state and the reason.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));

    $this->department = Department::factory()->create(['service_rules' => ['intake.confirmation_required' => true]]);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create(['name' => 'Sofía']);
    $this->incomplete = Conversation::factory()->inDepartment($this->department)->create([
        'readiness' => ConversationReadiness::NeedsInfo,
        'queued_at' => null,
        'created_at' => CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'),
    ]);
});

function setReadiness(object $test, User $actor, Conversation $conversation, array $data): TestResponse
{
    return $test->actingAs($actor)->postJson(route('conversations.readiness.update', $conversation), $data);
}

test('confirming a request as complete puts it in the ready queue and records who did it', function () {
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready', 'version' => 0])->assertRedirect();

    $event = $this->incomplete->events()->where('type', ConversationEventType::ReadinessConfirmed)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ConversationReadinessConfirmed->value)->sole();

    expect($this->incomplete->fresh())
        ->readiness->toBe(ConversationReadiness::Ready)
        ->queued_at->toEqual(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'))
        ->readiness_changed_at->toEqual(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'))
        ->readiness_changed_by_user_id->toBe($this->supervisor->id)
        ->status->toBe(ConversationStatus::Pending)
        ->version->toBe(1)
        ->and($event->actor_user_id)->toBe($this->supervisor->id)
        ->and($event->metadata)->toBe(['previous' => 'needs_info', 'new' => 'ready'])
        ->and($audit->user_id)->toBe($this->supervisor->id)
        ->and($audit->auditable_id)->toBe($this->incomplete->id)
        ->and($audit->properties)->toMatchArray(['previous_readiness' => 'needs_info', 'new_readiness' => 'ready', 'reason' => null]);
});

test('the minimum wait counts from the confirmation', function () {
    $this->department->forceFill(['service_rules' => ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 5]])->save();
    $accept = fn () => $this->actingAs($this->agent)->postJson(route('conversations.accept', $this->incomplete));

    $accept()->assertConflict()->assertJsonPath('code', 'needs_info');

    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready'])->assertRedirect();
    $accept()->assertConflict()->assertJsonPath('code', 'min_wait')
        ->assertJsonPath('available_at', CarbonImmutable::parse('2026-11-10 16:05:00', 'UTC')->toIso8601String());

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:05:00', 'UTC'));
    $accept()->assertRedirect();
});

test('returning a request to needs information takes it out of the ready queue with a reason', function () {
    $ready = Conversation::factory()->inDepartment($this->department)->create(['queued_at' => CarbonImmutable::parse('2026-11-10 15:00', 'UTC')]);

    setReadiness($this, $this->supervisor, $ready, ['readiness' => 'needs_info', 'reason' => 'Falta el número de pedido'])->assertRedirect();

    $event = $ready->events()->where('type', ConversationEventType::ReadinessReverted)->sole();

    expect($ready->fresh())
        ->readiness->toBe(ConversationReadiness::NeedsInfo)
        ->queued_at->toBeNull()
        ->and($event->note)->toBe('Falta el número de pedido')
        ->and(AuditLog::query()->where('action', AuditAction::ConversationReadinessReverted->value)->sole()->properties)->toMatchArray([
            'previous_readiness' => 'ready',
            'new_readiness' => 'needs_info',
            'reason' => 'Falta el número de pedido',
        ]);

    $this->actingAs($this->agent)->postJson(route('conversations.accept', $ready))->assertConflict()->assertJsonPath('code', 'needs_info');
});

test('a reason of at least five characters is required to return a request', function (array $data) {
    $ready = Conversation::factory()->inDepartment($this->department)->create();

    setReadiness($this, $this->supervisor, $ready, ['readiness' => 'needs_info', ...$data])->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect($ready->fresh()->readiness)->toBe(ConversationReadiness::Ready);
})->with([
    'missing' => [[]],
    'too short' => [['reason' => 'dato']],
]);

test('an unknown readiness is rejected', function () {
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'complete'])->assertUnprocessable()->assertJsonValidationErrors('readiness');
});

test('asking for the state it already has changes nothing, even from a stale view', function () {
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready', 'version' => 0])->assertRedirect();
    // The same request retried: the version it carries is now stale.
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready', 'version' => 0])->assertRedirect();

    expect($this->incomplete->events()->where('type', ConversationEventType::ReadinessConfirmed)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AuditAction::ConversationReadinessConfirmed->value)->count())->toBe(1)
        ->and($this->incomplete->fresh()->version)->toBe(1);
});

test('a decision taken on a stale version of the conversation is a conflict', function () {
    $this->incomplete->forceFill(['version' => 3])->save();

    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready', 'version' => 2])
        ->assertConflict()
        ->assertJsonPath('code', 'state_changed');

    expect($this->incomplete->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo);
});

test('readiness needs the permission and the operational reach over the conversation', function (string $actor, int $status) {
    $user = match ($actor) {
        'agent without the permission' => $this->agent,
        'holder of another department' => User::factory()->supervisor()->inDepartments(Department::factory()->create())->create(),
        'holder with personal reach who does not attend it' => conversationUser([Permission::ViewConversations, Permission::ConfirmConversationReady], DataScope::Assigned, [$this->department]),
        'holder of the department' => conversationUser([Permission::ViewConversations, Permission::ConfirmConversationReady], DataScope::Departments, [$this->department]),
        'global holder' => User::factory()->administrator()->create(),
    };

    setReadiness($this, $user, $this->incomplete, ['readiness' => 'ready'])->assertStatus($status);

    expect($this->incomplete->fresh()->readiness)->toBe($status === 302 ? ConversationReadiness::Ready : ConversationReadiness::NeedsInfo);
})->with([
    'agent without the permission' => ['agent without the permission', 403],
    'holder of another department' => ['holder of another department', 404],
    'holder with personal reach who does not attend it' => ['holder with personal reach who does not attend it', 404],
    'holder of the department' => ['holder of the department', 302],
    'global holder' => ['global holder', 302],
]);

test('a closed conversation has nothing to confirm and imported history is read-only', function () {
    $closed = Conversation::factory()->inDepartment($this->department)->closed()->create(['readiness' => ConversationReadiness::NeedsInfo]);
    $imported = Conversation::factory()->inDepartment($this->department)->closed()->create();
    $imported->forceFill(['origin' => 'whaticket'])->save();

    setReadiness($this, $this->supervisor, $closed, ['readiness' => 'ready'])->assertConflict()->assertJsonPath('code', 'closed');
    setReadiness($this, $this->supervisor, $imported, ['readiness' => 'needs_info', 'reason' => 'Faltan datos'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Esta conversación es un historial importado y es de solo consulta.');

    expect($closed->fresh()->readiness)->toBe(ConversationReadiness::NeedsInfo);
});

test('an open conversation marked as needing information stays with its agent', function () {
    $open = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['queued_at' => CarbonImmutable::parse('2026-11-10 15:00', 'UTC')]);

    setReadiness($this, $this->supervisor, $open, ['readiness' => 'needs_info', 'reason' => 'Falta la factura'])->assertRedirect();

    expect($open->fresh())
        ->readiness->toBe(ConversationReadiness::NeedsInfo)
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id)
        ->queued_at->toEqual(CarbonImmutable::parse('2026-11-10 15:00', 'UTC'));
});

test('confirming a request of an automatic department offers its queue again', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    $this->supervisor->departments()->detach();
    $admin = User::factory()->administrator()->create();

    setReadiness($this, $admin, $this->incomplete, ['readiness' => 'ready'])->assertRedirect();

    expect($this->incomplete->fresh())->status->toBe(ConversationStatus::Open)->assigned_user_id->toBe($this->agent->id);
});

test('the readiness changes are shown in the chat timeline with their reason', function () {
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'ready'])->assertRedirect();
    $this->travel(1)->minutes();
    setReadiness($this, $this->supervisor, $this->incomplete, ['readiness' => 'needs_info', 'reason' => 'Falta el comprobante'])->assertRedirect();

    $events = collect($this->actingAs($this->supervisor)->get(route('conversations.index', ['c' => $this->incomplete->id]))->viewData('page')['props']['timeline']['events']);

    expect($events->pluck('label')->all())->toContain('Solicitud confirmada como completa', 'Marcada como «Necesita información»')
        ->and($events->firstWhere('type', 'readiness_reverted'))->toMatchArray(['note' => 'Falta el comprobante', 'actor' => 'Sofía']);
});
