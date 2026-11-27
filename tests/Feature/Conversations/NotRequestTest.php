<?php

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\Permission;
use App\Http\Resources\ConversationResource;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\ConversationMetricsCalculator;
use App\Services\Analytics\ServiceLevelAnalytics;
use App\Services\DashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
| «No es solicitud»: a «gracias», an emoji or an «ok» after a finished
| request starts a new conversation in «Pendientes». Whoever could accept or
| attend it closes it in one click, without accepting it first and without
| sending anything to the customer; it is outside every metric, the next
| message starts a new conversation, and «Deshacer» puts it back for a few
| minutes.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 16:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->operational()->create([
        'default_department_id' => $this->department->id,
        'reopen_window_minutes' => 120,
        'farewell_enabled' => true,
        'farewell_message' => 'Gracias por contactarnos.',
    ]);
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
});

function markNotRequest(User $actor, Conversation $conversation): TestResponse
{
    return test()->actingAs($actor)
        ->from(route('conversations.index'))
        ->post(route('conversations.not-request.store', $conversation), ['version' => $conversation->refresh()->version]);
}

function undoNotRequest(User $actor, Conversation $conversation): TestResponse
{
    return test()->actingAs($actor)
        ->from(route('conversations.index'))
        ->delete(route('conversations.not-request.destroy', $conversation), ['version' => $conversation->refresh()->version]);
}

test('a pending request is marked from the queue without accepting it, whatever the queue rules say', function () {
    config(['features.phase_two' => true]);
    $this->department->forceFill(['service_rules' => ['queue.chronological_order' => true, 'queue.min_wait_minutes' => 30]])->save();
    // An earlier request waits first and the minimum wait has not passed: accepting is blocked.
    Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id, 'queued_at' => now()->subMinutes(20)]);
    $thanks = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id, 'queued_at' => now()->subMinute()]);

    expect((new ConversationResource($thanks))->resolve(request()->setUserResolver(fn () => $this->agent))['can'])
        ->toMatchArray(['mark_not_request' => true, 'undo_not_request' => false]);

    markNotRequest($this->agent, $thanks)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.action.label', 'Deshacer');

    $thanks->refresh();
    $event = $thanks->events()->where('type', ConversationEventType::NotRequest)->sole();

    expect($thanks)
        ->status->toBe(ConversationStatus::Closed)
        ->closed_by_user_id->toBe($this->agent->id)
        ->not_request_by_user_id->toBe($this->agent->id)
        ->completed_at->toBeNull()
        // Kept by whoever dismissed it: it is in their «Cerradas» and they can undo it.
        ->assigned_user_id->toBe($this->agent->id)
        ->and($thanks->not_request_at)->not->toBeNull()
        ->and($event->actor_user_id)->toBe($this->agent->id)
        ->and($event->from_user_id)->toBeNull()
        ->and($event->metadata)->toBe(['from_status' => 'pending'])
        ->and($thanks->events()->where('type', ConversationEventType::Closed)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationMarkedNotRequest)->sole())
        ->auditable_id->toBe($thanks->id)
        ->user_id->toBe($this->agent->id);
});

test('the responsible agent marks an open conversation and nothing is sent to the customer', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['connection_id' => $this->connection->id]);

    markNotRequest($this->agent, $conversation)->assertRedirect()->assertSessionHasNoErrors();

    $conversation->refresh();

    expect($conversation)
        ->status->toBe(ConversationStatus::Closed)
        ->assigned_user_id->toBe($this->agent->id)
        ->and($conversation->events()->where('type', ConversationEventType::NotRequest)->sole())
        ->from_user_id->toBe($this->agent->id)
        ->metadata->toBe(['from_status' => 'open'])
        // No farewell, no message of any kind.
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('direction', MessageDirection::Outbound)->exists())->toBeFalse();
});

test('after «No es solicitud» the next message starts a new conversation, even inside the reopening window', function () {
    $first = receiveWhatsapp($this->connection, body: 'Gracias 🙏');

    markNotRequest($this->agent, $first->conversation)->assertSessionHasNoErrors();
    $this->travel(5)->minutes();

    $receipt = receiveWhatsapp($this->connection, body: 'Hola, quiero otro crédito');

    expect($receipt->outcome)->toBe(InboundMessageOutcome::Created)
        ->and($receipt->conversation->id)->not->toBe($first->conversation->id)
        ->and($receipt->conversation->status)->toBe(ConversationStatus::Pending)
        ->and($first->conversation->refresh())
        ->status->toBe(ConversationStatus::Closed)
        ->reopen_count->toBe(0);
});

test('it is outside the metrics, the dashboard and the service level', function () {
    $admin = User::factory()->administrator()->create();
    $thanks = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id]);
    $request = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['connection_id' => $this->connection->id]);
    $calculator = app(ConversationMetricsCalculator::class);
    $calculator->recompute($thanks->id);

    foreach ([$thanks, $request] as $conversation) {
        ResponseCycle::factory()->create([
            'conversation_id' => $conversation->id,
            'started_at' => now()->subHour(),
            'target_seconds' => 300,
            'business_hours_only' => false,
            'due_at' => now()->subMinutes(55),
            'breached_at' => now()->subMinutes(55),
        ]);
    }

    expect(ConversationMetric::query()->where('conversation_id', $thanks->id)->exists())->toBeTrue();

    markNotRequest($this->agent, $thanks)->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post(route('conversations.close', $request), ['version' => $request->refresh()->version, 'completed' => true])->assertSessionHasNoErrors();

    $filters = AnalyticsFilters::from(['period' => '7d'], $admin, app(AnalyticsScope::class), 'America/Mexico_City');

    expect($calculator->recompute($thanks->id))->toBeNull()
        ->and(ConversationMetric::query()->where('conversation_id', $thanks->id)->exists())->toBeFalse()
        ->and(ConversationMetric::query()->where('conversation_id', $request->id)->exists())->toBeTrue()
        ->and(app(DashboardMetrics::class)->overviewFor($admin)['conversations']['closed_today'])->toBe(1)
        ->and(app(ServiceLevelAnalytics::class)->compliance($admin, $filters)['totals']['cycles'])->toBe(1);
});

test('«Deshacer» puts a dismissed request back in the queue with its clock, and it counts again', function () {
    $queuedAt = now()->subMinutes(15)->startOfSecond();
    $thanks = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id, 'queued_at' => $queuedAt]);

    markNotRequest($this->agent, $thanks)->assertSessionHasNoErrors();

    expect((new ConversationResource($thanks->refresh()))->resolve(request()->setUserResolver(fn () => $this->agent))['can']['undo_not_request'])->toBeTrue();

    undoNotRequest($this->agent, $thanks)->assertRedirect()->assertSessionHasNoErrors();

    expect($thanks->refresh())
        ->status->toBe(ConversationStatus::Pending)
        ->assigned_user_id->toBeNull()
        ->closed_at->toBeNull()
        ->not_request_at->toBeNull()
        ->reopen_count->toBe(0)
        ->and($thanks->queued_at->equalTo($queuedAt))->toBeTrue()
        ->and($thanks->events()->where('type', ConversationEventType::NotRequestUndone)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationNotRequestUndone)->exists())->toBeTrue()
        ->and(app(ConversationMetricsCalculator::class)->recompute($thanks->id))->not->toBeNull();
});

test('«Deshacer» returns an attended conversation to its agent and is no longer offered after the window', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['connection_id' => $this->connection->id]);

    markNotRequest($this->agent, $conversation)->assertSessionHasNoErrors();
    undoNotRequest($this->agent, $conversation)->assertSessionHasNoErrors();

    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->assigned_user_id->toBe($this->agent->id);

    markNotRequest($this->agent, $conversation)->assertSessionHasNoErrors();
    $this->travel(11)->minutes();

    undoNotRequest($this->agent, $conversation)->assertSessionHasErrors('conversation');

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

test('someone who cannot accept or attend it is refused', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id]);
    // Sees the department's conversations but may neither accept nor close them.
    $viewer = conversationUser([Permission::ViewConversations, Permission::ReplyConversations], DataScope::Departments, [$this->department]);
    // Accepts only from another department: the conversation does not exist for them.
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();

    expect((new ConversationResource($conversation))->resolve(request()->setUserResolver(fn () => $viewer))['can']['mark_not_request'])->toBeFalse();

    markNotRequest($viewer, $conversation)->assertForbidden();
    markNotRequest($outsider, $conversation)->assertNotFound();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->not_request_at)->toBeNull();
});

test('another agent of the department cannot dismiss a conversation someone else attends', function () {
    $colleague = User::factory()->agent()->inDepartments($this->department)->create();
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($colleague)->create(['connection_id' => $this->connection->id]);
    $supervisor = User::factory()->supervisor()->inDepartments($this->department)->create();

    expect((new ConversationResource($conversation))->resolve(request()->setUserResolver(fn () => $supervisor))['can']['mark_not_request'])->toBeTrue();

    // The agent does not even see it (assigned scope); a supervisor who assigns may.
    markNotRequest($this->agent, $conversation)->assertNotFound();
    markNotRequest($supervisor, $conversation)->assertSessionHasNoErrors();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

test('a closed conversation cannot be marked again', function () {
    $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create(['connection_id' => $this->connection->id]);

    markNotRequest($this->agent, $conversation)->assertSessionHasNoErrors();
    markNotRequest($this->agent, $conversation)->assertSessionHasErrors('conversation');

    expect($conversation->events()->where('type', ConversationEventType::NotRequest)->count())->toBe(1);
});
