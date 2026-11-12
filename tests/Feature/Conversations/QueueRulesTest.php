<?php

use App\Actions\Conversations\AssignAutomatically;
use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\QueueRules;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Rules of the queue: request completeness, minimum wait and chronological
| order are three separate rules, each off by default, resolved by category >
| department > platform and enforced by the engine on every way of taking a
| request (accept, self-assignment, transfer to oneself, automatic
| distribution). Capacity and the control of pending conversations keep
| their own mechanisms and are never bypassed by a queue exception.
*/

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));

    $this->department = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->supervisor = User::factory()->supervisor()->inDepartments($this->department)->create(['name' => 'Sofía']);
});

/**
 * A pending request of the department that entered the ready queue at the
 * given time of 2026-11-10 (UTC).
 *
 * @param  array<string, mixed>  $attributes
 */
function queueRequest(Department $department, string $queuedAt = '15:00:00', array $attributes = []): Conversation
{
    $at = CarbonImmutable::parse("2026-11-10 {$queuedAt}", 'UTC');

    return Conversation::factory()->inDepartment($department)->create(['created_at' => $at, 'queued_at' => $at, ...$attributes]);
}

function queueAccept(object $test, User $agent, Conversation $conversation, array $data = []): TestResponse
{
    return $test->actingAs($agent)->postJson(route('conversations.accept', $conversation), $data);
}

/**
 * @param  array<string, int|bool>  $rules
 */
function queueDepartmentRules(Department $department, array $rules): void
{
    $department->forceFill(['service_rules' => $rules])->save();
}

function queueRow(object $test, User $viewer, Conversation $conversation, array $query = []): array
{
    $rows = $test->actingAs($viewer)->get(route('conversations.index', $query))->viewData('page')['props']['conversations']['data'];

    return collect($rows)->firstWhere('id', $conversation->id);
}

test('with every rule off a pending request is accepted as before', function () {
    $request = queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);

    queueAccept($this, $this->agent, $request)->assertRedirect();

    expect($request->fresh())->status->toBe(ConversationStatus::Open)->assigned_user_id->toBe($this->agent->id);
});

test('an incomplete request cannot be accepted where the confirmation rule is on', function (string $level) {
    $category = ConversationCategory::factory()->create();
    $request = queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null, 'category_id' => $category->id]);

    match ($level) {
        'platform' => app(PlatformSettings::class)->update(['intake' => ['confirmation_required' => true]]),
        'department' => queueDepartmentRules($this->department, ['intake.confirmation_required' => true]),
        'category' => $category->forceFill(['service_rules' => ['intake.confirmation_required' => true]])->save(),
    };

    queueAccept($this, $this->agent, $request)
        ->assertConflict()
        ->assertExactJson([
            'code' => 'needs_info',
            'message' => 'Esta solicitud todavía necesita información. Podrá tomarse cuando se confirme como completa.',
            'available_at' => null,
            'blocking_conversation_id' => null,
            'overridable' => false,
        ]);

    expect($request->fresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull();
})->with(['platform', 'department', 'category']);

test('a category that does not require confirmation overrides its department', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $category = ConversationCategory::factory()->withServiceRules(['intake.confirmation_required' => false])->create();
    $request = queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null, 'category_id' => $category->id]);

    queueAccept($this, $this->agent, $request)->assertRedirect();

    expect($request->fresh()->assigned_user_id)->toBe($this->agent->id);
});

test('a new conversation is born needing information only where the rule is on at that moment', function () {
    $connection = Connection::factory()->create(['default_department_id' => $this->department->id]);

    $ready = receiveWhatsapp($connection, from: '5215511111111')->conversation;

    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $incomplete = receiveWhatsapp($connection, from: '5215522222222')->conversation;

    expect($ready->fresh())
        ->readiness->toBe(ConversationReadiness::Ready)
        ->queued_at->not->toBeNull()
        // Turning the rule on later never touches an existing conversation.
        ->and($incomplete->fresh())
        ->readiness->toBe(ConversationReadiness::NeedsInfo)
        ->queued_at->toBeNull()
        ->status->toBe(ConversationStatus::Pending);

    // It keeps receiving the customer's messages while it waits.
    $again = receiveWhatsapp($connection, from: '5215522222222', body: 'Adjunto mi folio');

    expect($again->conversation->id)->toBe($incomplete->id)
        ->and($incomplete->messages()->count())->toBe(2);
});

test('turning the confirmation rule off releases the requests it was holding', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $request = queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);

    queueAccept($this, $this->agent, $request)->assertConflict();

    queueDepartmentRules($this->department, []);

    queueAccept($this, $this->agent, $request)->assertRedirect();

    expect($request->fresh())
        ->assigned_user_id->toBe($this->agent->id)
        // Its readiness is history: nothing rewrote it.
        ->readiness->toBe(ConversationReadiness::NeedsInfo);
});

test('a request cannot be accepted before its minimum wait and can right after it', function () {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 5]]);
    $request = queueRequest($this->department, '15:58:00');

    queueAccept($this, $this->agent, $request)
        ->assertConflict()
        ->assertJsonPath('code', 'min_wait')
        ->assertJsonPath('message', 'Podrás tomarla dentro de 3 minutos.')
        ->assertJsonPath('available_at', CarbonImmutable::parse('2026-11-10 16:03:00', 'UTC')->toIso8601String());

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:02:59', 'UTC'));
    queueAccept($this, $this->agent, $request)->assertConflict()->assertJsonPath('message', 'Podrás tomarla dentro de 1 segundo.');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:03:00', 'UTC'));
    queueAccept($this, $this->agent, $request)->assertRedirect();

    expect($request->fresh()->assigned_user_id)->toBe($this->agent->id);
});

test('the minimum wait follows the hierarchy: category over department over platform', function (?int $department, ?int $category, bool $accepted) {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 10]]);
    queueDepartmentRules($this->department, array_filter(['queue.min_wait_minutes' => $department], fn (?int $value): bool => $value !== null));
    $requestCategory = ConversationCategory::factory()->withServiceRules(array_filter(['queue.min_wait_minutes' => $category], fn (?int $value): bool => $value !== null))->create();
    // Waiting for 4 minutes.
    $request = queueRequest($this->department, '15:56:00', ['category_id' => $requestCategory->id]);

    queueAccept($this, $this->agent, $request)->assertStatus($accepted ? 302 : 409);
})->with([
    'platform value' => [null, null, false],
    'shorter in the department' => [3, null, true],
    'longer in the category than in the department' => [3, 6, false],
    'no wait for the category' => [null, 0, true],
]);

test('with chronological order only the first eligible request of the department can be taken', function () {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true]);
    $first = queueRequest($this->department, '15:00:00');
    $second = queueRequest($this->department, '15:10:00');
    $third = queueRequest($this->department, '15:20:00');

    queueAccept($this, $this->agent, $third)
        ->assertConflict()
        ->assertExactJson([
            'code' => 'queue_order',
            'message' => 'Existe una solicitud anterior que debe atenderse primero.',
            'available_at' => null,
            'blocking_conversation_id' => $first->id,
            'overridable' => false,
        ]);
    queueAccept($this, $this->agent, $second)->assertConflict()->assertJsonPath('blocking_conversation_id', $first->id);

    queueAccept($this, $this->agent, $first)->assertRedirect();
    // The first one was taken: it no longer blocks, and now the second is the head.
    queueAccept($this, $this->agent, $third)->assertConflict()->assertJsonPath('blocking_conversation_id', $second->id);
    queueAccept($this, $this->agent, $second)->assertRedirect();
    queueAccept($this, $this->agent, $third)->assertRedirect();

    expect(Conversation::query()->where('assigned_user_id', $this->agent->id)->count())->toBe(3);
});

test('requests with the same clock are ordered by id', function () {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true]);
    $first = queueRequest($this->department, '15:00:00');
    $second = queueRequest($this->department, '15:00:00');

    queueAccept($this, $this->agent, $second)->assertConflict()->assertJsonPath('blocking_conversation_id', $first->id);
    queueAccept($this, $this->agent, $first)->assertRedirect();
});

test('earlier requests that are not comparable never block', function (string $earlier) {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true, 'intake.confirmation_required' => true]);

    $blocker = match ($earlier) {
        'of another department' => queueRequest(Department::factory()->create(), '15:00:00'),
        'without department' => Conversation::factory()->create(['queued_at' => CarbonImmutable::parse('2026-11-10 15:00', 'UTC')]),
        'still needing information' => queueRequest($this->department, '15:00:00', ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]),
        'still inside its minimum wait' => queueRequest($this->department, '15:00:00', [
            'category_id' => ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 120])->create()->id,
        ]),
        'already taken by a colleague' => queueRequest($this->department, '15:00:00', [
            'status' => ConversationStatus::Open,
            'assigned_user_id' => User::factory()->agent()->inDepartments($this->department)->create()->id,
        ]),
        'closed' => queueRequest($this->department, '15:00:00', ['status' => ConversationStatus::Closed, 'closed_at' => now()]),
    };
    $request = queueRequest($this->department, '15:30:00');

    queueAccept($this, $this->agent, $request)->assertRedirect();

    expect($request->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($blocker->fresh()->assigned_user_id)->not->toBe($this->agent->id);
})->with([
    'of another department',
    'without department',
    'still needing information',
    'still inside its minimum wait',
    'already taken by a colleague',
    'closed',
]);

test('chronological order off never restrains the queue', function () {
    queueRequest($this->department, '15:00:00');
    $later = queueRequest($this->department, '15:30:00');

    queueAccept($this, $this->agent, $later)->assertRedirect();
});

test('the rules are validated in order: completeness, minimum wait, then chronological order', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 30, 'queue.chronological_order' => true]);
    queueRequest($this->department, '14:00:00');
    $request = queueRequest($this->department, '15:50:00', ['readiness' => ConversationReadiness::NeedsInfo]);

    queueAccept($this, $this->agent, $request)->assertConflict()->assertJsonPath('code', 'needs_info');

    $request->forceFill(['readiness' => ConversationReadiness::Ready])->save();
    queueAccept($this, $this->agent, $request)->assertConflict()->assertJsonPath('code', 'min_wait');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:20:00', 'UTC'));
    queueAccept($this, $this->agent, $request)->assertConflict()->assertJsonPath('code', 'queue_order');
});

test('an inertia accept is refused with the specific explanation and its code', function () {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 5]]);
    $request = queueRequest($this->department, '15:58:00');

    $this->actingAs($this->agent)->from(route('conversations.index'))->post(route('conversations.accept', $request))
        ->assertRedirect(route('conversations.index'))
        ->assertSessionHasErrors([
            'conversation' => 'Podrás tomarla dentro de 3 minutos.',
            'conversation_conflict' => 'min_wait',
            'conversation_available_at' => CarbonImmutable::parse('2026-11-10 16:03:00', 'UTC')->toIso8601String(),
        ]);
});

test('a holder of the exception permission bypasses the queue rules with a reason, and it is recorded', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 30, 'queue.chronological_order' => true]);
    $head = queueRequest($this->department, '14:00:00');
    $request = queueRequest($this->department, '15:50:00', ['readiness' => ConversationReadiness::NeedsInfo]);

    // Without a reason the rules apply to the holder too, who learns an exception is possible.
    queueAccept($this, $this->supervisor, $request)->assertConflict()->assertJsonPath('code', 'needs_info')->assertJsonPath('overridable', true);

    queueAccept($this, $this->supervisor, $request, ['override_reason' => 'Cliente prioritario, lo pidió gerencia'])->assertRedirect();

    $event = $request->events()->where('type', ConversationEventType::Accepted)->sole();
    $audit = AuditLog::query()->where('action', AuditAction::ConversationQueueRulesOverridden->value)->sole();

    expect($request->fresh())->assigned_user_id->toBe($this->supervisor->id)
        ->and($event->metadata['queue_override'])->toBe([
            'rules' => ['needs_info', 'min_wait', 'queue_order'],
            'reason' => 'Cliente prioritario, lo pidió gerencia',
        ])
        ->and($event->actor_user_id)->toBe($this->supervisor->id)
        ->and($audit->user_id)->toBe($this->supervisor->id)
        ->and($audit->auditable_id)->toBe($request->id)
        ->and($audit->properties)->toMatchArray([
            'bypassed_rules' => ['needs_info', 'min_wait', 'queue_order'],
            'reason' => 'Cliente prioritario, lo pidió gerencia',
            'override_result' => 'accepted',
        ])
        ->and($head->fresh()->status)->toBe(ConversationStatus::Pending);
});

test('an exception reason is ignored without the permission and nothing is recorded when no rule was bypassed', function () {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 5]]);
    $waiting = queueRequest($this->department, '15:58:00');
    $available = queueRequest($this->department, '15:00:00');

    queueAccept($this, $this->agent, $waiting, ['override_reason' => 'Porque quiero tomarla ya mismo'])
        ->assertConflict()
        ->assertJsonPath('code', 'min_wait')
        ->assertJsonPath('overridable', false);

    queueAccept($this, $this->supervisor, $available, ['override_reason' => 'No hacía falta ninguna excepción'])->assertRedirect();

    expect($waiting->fresh()->assigned_user_id)->toBeNull()
        ->and($available->events()->where('type', ConversationEventType::Accepted)->sole()->metadata)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationQueueRulesOverridden->value)->count())->toBe(0);
});

test('an exception reason shorter than ten characters is rejected', function () {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 5]]);
    $request = queueRequest($this->department, '15:58:00');

    queueAccept($this, $this->supervisor, $request, ['override_reason' => 'urgente'])->assertUnprocessable()->assertJsonValidationErrors('override_reason');

    expect($request->fresh()->assigned_user_id)->toBeNull();
});

test('capacity and pending conversations are still enforced and are never bypassed by an exception', function (string $limit, string $message) {
    app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 5]]);

    if ($limit === 'capacity') {
        $this->supervisor->forceFill(['max_open_conversations' => 1])->save();
        Conversation::factory()->inDepartment($this->department)->assignedTo($this->supervisor)->create();
    } else {
        // The supervisor role is not exempt from the control of pending conversations.
        app(PlatformSettings::class)->update(['operations' => ['pending_block_enabled' => true, 'pending_block_after_minutes' => 10]]);
        Conversation::factory()->inDepartment($this->department)->assignedTo($this->supervisor)->create([
            'last_inbound_at' => CarbonImmutable::parse('2026-11-10 15:00', 'UTC'),
            'assigned_at' => CarbonImmutable::parse('2026-11-10 15:00', 'UTC'),
            'last_outbound_at' => null,
        ]);
    }

    $request = queueRequest($this->department, '15:58:00');

    queueAccept($this, $this->supervisor, $request, ['override_reason' => 'Cliente prioritario, lo pidió gerencia'])
        ->assertConflict()
        ->assertExactJson(['code' => 'conflict', 'message' => $message]);

    expect($request->fresh()->assigned_user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationQueueRulesOverridden->value)->count())->toBe(0);
})->with([
    'capacity limit' => ['capacity', 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.'],
    'pending conversations' => ['pending', 'Tienes conversaciones pendientes de atención. Revísalas antes de aceptar una nueva.'],
]);

test('assigning to oneself is taking the request; distributing to someone else only requires completeness', function () {
    queueDepartmentRules($this->department, ['queue.min_wait_minutes' => 30, 'queue.chronological_order' => true]);
    queueRequest($this->department, '14:00:00');
    $request = queueRequest($this->department, '15:50:00');
    $assign = fn (User $to, array $extra = []) => $this->actingAs($this->supervisor)
        ->postJson(route('conversations.assign', $request), ['user_id' => $to->id, 'version' => $request->fresh()->version, ...$extra]);

    $assign($this->supervisor)->assertConflict()->assertJsonPath('code', 'min_wait');
    expect($request->fresh()->assigned_user_id)->toBeNull();

    // Deliberate distribution to a colleague: neither the wait nor the order apply.
    $assign($this->agent)->assertRedirect();

    expect($request->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($request->events()->where('type', ConversationEventType::Assigned)->sole()->metadata)->toBeNull();
});

test('handing over an incomplete request requires a recorded exception, whoever receives it', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $request = queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);
    $distributor = conversationUser(
        [Permission::ViewConversations, Permission::AssignConversations, Permission::TransferConversations],
        DataScope::Departments,
        [$this->department],
    );

    $this->actingAs($distributor)->postJson(route('conversations.assign', $request), ['user_id' => $this->agent->id, 'version' => 0, 'override_reason' => 'La tomará de todas formas'])
        ->assertConflict()->assertJsonPath('code', 'needs_info')->assertJsonPath('overridable', false);
    $this->actingAs($distributor)->postJson(route('conversations.transfer', $request), ['user_id' => $this->agent->id, 'version' => 0, 'reason' => 'Urge'])
        ->assertConflict()->assertJsonPath('code', 'needs_info');

    $this->actingAs($this->supervisor)->postJson(route('conversations.assign', $request), ['user_id' => $this->agent->id, 'version' => 0, 'override_reason' => 'El cliente llamó y dio los datos'])
        ->assertRedirect();

    expect($request->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($request->events()->where('type', ConversationEventType::Assigned)->sole()->metadata['queue_override'])->toBe([
            'rules' => ['needs_info'],
            'reason' => 'El cliente llamó y dio los datos',
        ])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationQueueRulesOverridden->value)->sole()->properties)->toMatchArray([
            'override_result' => 'assigned',
            'assigned_to' => 'Ana',
        ]);
});

test('transferring a pending request to oneself is taking it; to a colleague or a queue it is not', function () {
    queueDepartmentRules($this->department, ['queue.min_wait_minutes' => 30]);
    $toSelf = queueRequest($this->department, '15:50:00');
    $toColleague = queueRequest($this->department, '15:50:00');
    $toQueue = queueRequest($this->department, '15:50:00');
    $other = Department::factory()->create();
    $transfer = fn (Conversation $conversation, array $data) => $this->actingAs($this->supervisor)
        ->postJson(route('conversations.transfer', $conversation), ['version' => 0, 'reason' => 'Reparto', ...$data]);

    $transfer($toSelf, ['user_id' => $this->supervisor->id])->assertConflict()->assertJsonPath('code', 'min_wait');
    $transfer($toColleague, ['user_id' => $this->agent->id])->assertRedirect();
    $transfer($toQueue, ['department_id' => $other->id])->assertRedirect();

    expect($toSelf->fresh()->assigned_user_id)->toBeNull()
        ->and($toColleague->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($toQueue->fresh())->department_id->toBe($other->id)->status->toBe(ConversationStatus::Pending);
});

test('reassigning a conversation already in attention is not restrained by the queue rules', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 30, 'queue.chronological_order' => true]);
    queueRequest($this->department, '14:00:00');
    $open = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['readiness' => ConversationReadiness::NeedsInfo]);

    $this->actingAs($this->supervisor)->postJson(route('conversations.assign', $open), ['user_id' => $this->supervisor->id, 'version' => 0])->assertRedirect();

    expect($open->fresh()->assigned_user_id)->toBe($this->supervisor->id);
});

test('automatic distribution never assigns a request that is incomplete or still waiting', function (array $rules, array $attributes) {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'service_rules' => $rules])->save();
    $request = queueRequest($this->department, '15:58:00', $attributes);

    $agent = DB::transaction(fn () => app(AssignAutomatically::class)->handle(Conversation::query()->lockForUpdate()->findOrFail($request->id)));

    expect($agent)->toBeNull()
        ->and($request->fresh())->status->toBe(ConversationStatus::Pending)->assigned_user_id->toBeNull();
})->with([
    'incomplete' => [['intake.confirmation_required' => true], ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]],
    'inside its minimum wait' => [['queue.min_wait_minutes' => 5], []],
]);

test('a queue is distributed in the order of its clock, skipping the requests that are not eligible', function () {
    $this->department->forceFill([
        'distribution_mode' => DistributionMode::Automatic,
        'service_rules' => ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 5],
        'max_open_conversations_per_agent' => 2,
    ])->save();
    $this->supervisor->departments()->detach();

    $incomplete = queueRequest($this->department, '14:00:00', ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);
    // Created first, but it went back to the queue later: its clock decides.
    $requeued = queueRequest($this->department, '15:40:00', ['created_at' => CarbonImmutable::parse('2026-11-10 13:00', 'UTC')]);
    $oldest = queueRequest($this->department, '15:10:00');
    $second = queueRequest($this->department, '15:20:00');
    $waiting = queueRequest($this->department, '15:58:00');

    $assigned = app(AssignAutomatically::class)->distributeQueue($this->department->fresh());

    expect($assigned)->toBe(2)
        ->and($oldest->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($second->fresh()->assigned_user_id)->toBe($this->agent->id)
        // No capacity left: the pass stopped there.
        ->and($requeued->fresh()->assigned_user_id)->toBeNull()
        ->and($incomplete->fresh()->assigned_user_id)->toBeNull()
        ->and($waiting->fresh()->assigned_user_id)->toBeNull();
});

test('the scheduled command offers an automatic queue once the minimum wait of a request ended', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'service_rules' => ['queue.min_wait_minutes' => 5]])->save();
    $this->supervisor->departments()->detach();
    $request = queueRequest($this->department, '15:58:00');

    $this->artisan('conversations:offer-waiting-queues')->assertSuccessful();
    expect($request->fresh()->assigned_user_id)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:03:20', 'UTC'));
    $this->artisan('conversations:offer-waiting-queues')->assertSuccessful();

    expect($request->fresh())->assigned_user_id->toBe($this->agent->id)->status->toBe(ConversationStatus::Open);
});

test('the scheduled command reads one row per automatic department when nothing can be taken', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'service_rules' => ['queue.min_wait_minutes' => 5]])->save();
    Department::factory()->create(['distribution_mode' => DistributionMode::Manual]);
    $waiting = queueRequest($this->department, '15:58:00');

    $conversationQueries = 0;
    DB::listen(function ($query) use (&$conversationQueries): void {
        $conversationQueries += preg_match('/from `conversations`/', $query->sql);
    });

    $this->artisan('conversations:offer-waiting-queues')->assertSuccessful();

    expect($conversationQueries)->toBe(1)
        ->and($waiting->fresh()->assigned_user_id)->toBeNull();
});

test('the scheduled command offers whatever is eligible now, however long ago it became so', function (array $rules, array $attributes) {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'service_rules' => $rules])->save();
    $this->supervisor->departments()->detach();
    // Eligible for a long time (a scheduler outage, a rule relaxed meanwhile).
    $request = queueRequest($this->department, '13:00:00', $attributes);

    $this->artisan('conversations:offer-waiting-queues')->assertSuccessful();

    expect($request->fresh())->assigned_user_id->toBe($this->agent->id)->status->toBe(ConversationStatus::Open);
})->with([
    'its wait ended long ago' => [['queue.min_wait_minutes' => 5], []],
    'no minimum wait at all' => [[], []],
    'confirmation was turned off' => [['intake.confirmation_required' => false], ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]],
]);

test('relaxing a queue rule offers the automatic queues right after the change', function (string $level) {
    $admin = User::factory()->superAdministrator()->create();
    $category = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 30])->create();
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'service_rules' => ['queue.min_wait_minutes' => 30]])->save();
    $this->supervisor->departments()->detach();
    $request = queueRequest($this->department, '15:50:00', ['category_id' => $category->id]);

    expect(app(AssignAutomatically::class)->distributeQueue($this->department->fresh()))->toBe(0);

    $response = match ($level) {
        'department' => (function () use ($admin, $category) {
            $category->forceFill(['service_rules' => null])->save();

            return $this->actingAs($admin)->put(route('departments.service-rules.update', $this->department), ['rules' => ['queue' => ['min_wait_minutes' => 5]]]);
        })(),
        'category' => $this->actingAs($admin)->put(route('categories.rules.update', $category), ['rules' => ['queue' => ['min_wait_minutes' => 5]]]),
        'platform' => (function () use ($admin, $category) {
            app(PlatformSettings::class)->update(['queue' => ['min_wait_minutes' => 30]]);
            $category->forceFill(['service_rules' => null])->save();
            $this->department->forceFill(['service_rules' => null])->save();

            $defaults = app(PlatformSettings::class)->defaults();

            return $this->actingAs($admin)->put(route('admin.settings.update'), [
                'general' => $defaults['general'],
                'business_hours' => $defaults['business_hours'],
                'operations' => $defaults['operations'],
                'queue' => ['min_wait_minutes' => 5],
            ]);
        })(),
    };

    $response->assertSessionHasNoErrors();

    expect($request->fresh())->assigned_user_id->toBe($this->agent->id)->status->toBe(ConversationStatus::Open);
})->with(['platform', 'department', 'category']);

test('the queue clock restarts every time a request goes back to a queue as ready', function () {
    $other = Department::factory()->create();
    $closer = fn (Conversation $conversation) => $conversation->forceFill(['status' => ConversationStatus::Closed, 'closed_at' => now(), 'assigned_user_id' => null])->save();
    $old = CarbonImmutable::parse('2026-11-10 09:00', 'UTC');
    $attended = fn () => Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['queued_at' => $old]);

    $released = $attended();
    $this->actingAs($this->agent)->post(route('conversations.release', $released), ['version' => 0])->assertSessionHasNoErrors();

    $transferred = $attended();
    $this->actingAs($this->agent)->post(route('conversations.transfer', $transferred), ['version' => 0, 'department_id' => $other->id, 'reason' => 'Corresponde a otra área'])->assertSessionHasNoErrors();

    $reopened = $attended();
    $closer($reopened);
    $this->actingAs($this->supervisor)->post(route('conversations.reopen', $reopened), ['version' => 0])->assertSessionHasNoErrors();

    $lostAccess = $attended();
    app(ReleaseAssignedConversations::class)->handle($this->agent, $this->department->id);

    foreach ([$released, $transferred, $reopened, $lostAccess] as $conversation) {
        expect($conversation->fresh())
            ->status->toBe(ConversationStatus::Pending)
            ->queued_at->toEqual(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));
    }
});

test('a request that still needs information goes back to a queue without a queue clock', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create([
        'readiness' => ConversationReadiness::NeedsInfo,
        'queued_at' => CarbonImmutable::parse('2026-11-10 09:00', 'UTC'),
    ]);

    $this->actingAs($this->agent)->post(route('conversations.release', $conversation), ['version' => 0])->assertSessionHasNoErrors();

    expect($conversation->fresh())->status->toBe(ConversationStatus::Pending)->queued_at->toBeNull();
});

test('the inbox tells for every pending row whether the viewer can take it and why not', function () {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true, 'queue.min_wait_minutes' => 5, 'queue.chronological_order' => true]);
    $head = queueRequest($this->department, '15:00:00');
    $behind = queueRequest($this->department, '15:10:00');
    $waiting = queueRequest($this->department, '15:58:00');
    $incomplete = queueRequest($this->department, '14:00:00', ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);
    $mine = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('serverTime', CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC')->toIso8601String())
            ->where('conversations.server_time', CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC')->toIso8601String()));

    expect(queueRow($this, $this->agent, $head))
        ->acceptance->toBe(['allowed' => true, 'available' => true, 'code' => null, 'message' => null, 'available_at' => null, 'blocking_conversation_id' => null, 'overridable' => false])
        ->readiness->toBe(['value' => 'ready', 'label' => 'Lista para atención', 'tracked' => true])
        ->queued_at->toBe(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC')->toIso8601String())
        ->and(queueRow($this, $this->agent, $behind)['acceptance'])->toMatchArray(['allowed' => false, 'available' => false, 'code' => 'queue_order', 'blocking_conversation_id' => $head->id, 'overridable' => false])
        ->and(queueRow($this, $this->agent, $waiting)['acceptance'])->toMatchArray([
            'allowed' => false,
            'code' => 'min_wait',
            'available_at' => CarbonImmutable::parse('2026-11-10 16:03:00', 'UTC')->toIso8601String(),
        ])
        ->and(queueRow($this, $this->agent, $incomplete))
        ->readiness->toBe(['value' => 'needs_info', 'label' => 'Necesita información', 'tracked' => true])
        ->acceptance->toMatchArray(['allowed' => false, 'code' => 'needs_info', 'message' => QueueRules::NEEDS_INFO_MESSAGE])
        ->and(queueRow($this, $this->agent, $mine)['acceptance'])->toBeNull()
        // The holder of the exception permission is told it can be overridden.
        ->and(queueRow($this, $this->supervisor, $behind, ['view' => 'general'])['acceptance']['overridable'])->toBeTrue()
        ->and(queueRow($this, $this->supervisor, $behind, ['view' => 'general'])['can'])->toMatchArray(['confirm_ready' => true, 'override_queue_rules' => true, 'categorize' => true])
        ->and(queueRow($this, $this->agent, $behind)['can'])->toMatchArray(['confirm_ready' => false, 'override_queue_rules' => false, 'categorize' => false]);
});

test('an available request tells the viewer their own limit when that is what stops them', function () {
    $request = queueRequest($this->department);
    $this->agent->forceFill(['max_open_conversations' => 1])->save();
    Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();

    expect(queueRow($this, $this->agent->fresh(), $request)['acceptance'])->toBe([
        'allowed' => false,
        'available' => true,
        'code' => 'capacity',
        'message' => 'Ya atiendes el máximo de 1 conversaciones permitido. Cierra o libera alguna antes de aceptar otra.',
        'available_at' => null,
        'blocking_conversation_id' => null,
        'overridable' => false,
    ]);
});

test('describing the acceptance of a page of rows does not grow with the number of rows', function () {
    queueDepartmentRules($this->department, ['queue.min_wait_minutes' => 1, 'queue.chronological_order' => true]);
    $second = Department::factory()->create(['service_rules' => ['queue.chronological_order' => true]]);
    $this->agent->departments()->attach($second);
    $category = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 2])->create();

    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($this->agent->fresh())->get(route('conversations.index'))->assertOk();

        return $queries;
    };

    foreach (range(1, 3) as $minute) {
        queueRequest($this->department, "15:0{$minute}:00");
        queueRequest($second, "15:0{$minute}:00", ['category_id' => $category->id]);
    }

    // Once before measuring: settings, permissions and rules are memoized per process.
    $count();
    $few = $count();

    foreach (range(10, 27) as $minute) {
        queueRequest($this->department, "15:{$minute}:00", ['category_id' => $minute % 2 === 0 ? $category->id : null]);
        queueRequest($second, "15:{$minute}:00");
    }

    // A full page of 25 rows of two departments costs no more than six rows did.
    expect($count())->toBeLessThanOrEqual($few);
});

/**
 * Older requests of the department that cannot be taken yet: their category
 * makes them wait for hours.
 */
function queueBacklog(Department $department, int $count): ConversationCategory
{
    $slow = ConversationCategory::factory()->withServiceRules(['queue.min_wait_minutes' => 600])->create();
    $at = CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC');

    Conversation::factory()->count($count)->inDepartment($department)->create(['created_at' => $at, 'queued_at' => $at, 'category_id' => $slow->id]);

    return $slow;
}

test('a long backlog of requests that cannot be taken never hides an earlier eligible one from the order rule', function () {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true]);
    queueBacklog($this->department, 60);
    $regular = ConversationCategory::factory()->create();
    $head = queueRequest($this->department, '15:00:00', ['category_id' => $regular->id]);
    $target = queueRequest($this->department, '15:10:00', ['category_id' => $regular->id]);

    queueAccept($this, $this->agent, $target)->assertConflict()
        ->assertJsonPath('code', 'queue_order')
        ->assertJsonPath('blocking_conversation_id', $head->id);

    expect($target->fresh()->assigned_user_id)->toBeNull()
        ->and(queueRow($this, $this->agent, $target, ['category' => $regular->id])['acceptance'])->toMatchArray(['allowed' => false, 'code' => 'queue_order', 'blocking_conversation_id' => $head->id])
        ->and(queueRow($this, $this->agent, $head, ['category' => $regular->id])['acceptance'])->toMatchArray(['allowed' => true, 'code' => null]);

    queueAccept($this, $this->agent, $head)->assertRedirect();
    queueAccept($this, $this->agent, $target)->assertRedirect();
});

test('automatic distribution reaches the eligible requests behind a backlog longer than one pass', function () {
    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic])->save();
    $this->supervisor->departments()->detach();
    queueBacklog($this->department, AssignAutomatically::QUEUE_PASS + 5);
    $first = queueRequest($this->department, '15:00:00');
    $second = queueRequest($this->department, '15:10:00');

    expect(app(AssignAutomatically::class)->distributeQueue($this->department->fresh()))->toBe(2)
        ->and($first->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($second->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and(Conversation::query()->where('status', ConversationStatus::Pending)->count())->toBe(AssignAutomatically::QUEUE_PASS + 5);
});

test('a ready request without a queue clock takes its place by its creation time, for the order rule and for the distribution alike', function () {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true]);
    $queued = queueRequest($this->department, '15:10:00');
    // Older data: ready, but it never got a queue clock.
    $unclocked = queueRequest($this->department, '15:05:00', ['queued_at' => null]);

    queueAccept($this, $this->agent, $queued)->assertConflict()->assertJsonPath('blocking_conversation_id', $unclocked->id);

    $this->department->forceFill(['distribution_mode' => DistributionMode::Automatic, 'max_open_conversations_per_agent' => 1])->save();
    $this->supervisor->departments()->detach();

    expect(app(AssignAutomatically::class)->distributeQueue($this->department->fresh()))->toBe(1)
        ->and($unclocked->fresh()->assigned_user_id)->toBe($this->agent->id)
        ->and($queued->fresh()->assigned_user_id)->toBeNull();
});

test('the earlier request is only named to someone who can see it or take it', function () {
    queueDepartmentRules($this->department, ['queue.chronological_order' => true]);
    $elsewhere = Department::factory()->create();
    $watcher = conversationUser([Permission::ViewConversations, Permission::SuperviseConversations], DataScope::Departments, [$elsewhere]);
    $head = queueRequest($this->department, '15:00:00');
    $behind = queueRequest($this->department, '15:10:00');
    // The watcher sees it only because it passed through their department.
    $behind->events()->create(['type' => ConversationEventType::Transferred, 'from_department_id' => $elsewhere->id, 'to_department_id' => $this->department->id, 'created_at' => now()]);

    $this->actingAs($watcher)->getJson(route('conversations.preview', $head))->assertNotFound();

    $this->actingAs($watcher)->getJson(route('conversations.preview', $behind))->assertOk()
        ->assertJsonPath('conversation.acceptance.code', 'queue_order')
        ->assertJsonPath('conversation.acceptance.message', QueueRules::QUEUE_ORDER_MESSAGE)
        ->assertJsonPath('conversation.acceptance.blocking_conversation_id', null);
    expect(queueRow($this, $watcher, $behind, ['view' => 'general'])['acceptance'])->toMatchArray(['code' => 'queue_order', 'blocking_conversation_id' => null]);

    // Whoever takes requests from that queue is still told which one goes first.
    $this->actingAs($this->agent)->getJson(route('conversations.preview', $behind))
        ->assertJsonPath('conversation.acceptance.blocking_conversation_id', $head->id);
});

/**
 * Ids of the user rows locked (`for update`) while the callback runs, in order.
 *
 * @return list<int>
 */
function queueLockedUserIds(callable $callback): array
{
    $locked = [];
    $recording = true;

    DB::listen(function ($query) use (&$locked, &$recording): void {
        if ($recording && preg_match('/from `users` where .*for update$/', $query->sql) === 1) {
            $locked[] = (int) $query->bindings[0];
        }
    });

    $callback();
    $recording = false;

    return $locked;
}

test('an exception is decided on the locked row of whoever makes it, locked in id order with the agent', function (string $operation) {
    queueDepartmentRules($this->department, ['intake.confirmation_required' => true]);
    $incomplete = fn () => queueRequest($this->department, attributes: ['readiness' => ConversationReadiness::NeedsInfo, 'queued_at' => null]);
    $send = fn (User $actor, Conversation $conversation, User $to, array $data = []) => $this->actingAs($actor)
        ->postJson(route("conversations.{$operation}", $conversation), ['user_id' => $to->id, 'version' => 0, ...$data])
        ->assertRedirect();
    // An actor created after the agent and one created before it: both orders.
    $later = User::factory()->supervisor()->inDepartments($this->department)->create();

    foreach ([$this->supervisor, $later] as $actor) {
        $locked = queueLockedUserIds(fn () => $send($actor, $incomplete(), $this->agent, ['override_reason' => 'El cliente ya envió todo por correo']));
        $expected = [$actor->id, $this->agent->id];
        sort($expected);

        expect($locked)->toBe($expected);
    }

    // Nothing to override: only the receiving agent is locked, as before.
    expect(queueLockedUserIds(fn () => $send($this->supervisor, queueRequest($this->department), $this->agent)))->toBe([$this->agent->id]);
})->with(['assign', 'transfer']);

test('a user who sees a request but may not take it is refused before their input is validated', function () {
    $reader = conversationUser([Permission::ViewConversations], DataScope::Departments, [$this->department]);
    $request = queueRequest($this->department);

    queueAccept($this, $reader, $request, ['override_reason' => ['not', 'a', 'string']])->assertForbidden();
    // Whoever may take it still has their input validated.
    queueAccept($this, $this->agent, $request, ['override_reason' => ['not', 'a', 'string']])->assertUnprocessable();
});
