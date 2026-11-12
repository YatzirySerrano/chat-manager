<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ReopenConversation;
use App\Actions\Conversations\TransferConversation;
use App\Enums\ConversationEventType;
use App\Enums\DataScope;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Permission;
use App\Events\Messaging\MessageRecorded;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationAgentMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Reports\Metric;
use App\Reports\ReportRegistry;
use App\Services\Analytics\AgentProductivity;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Exports\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Per-person attribution (conversation_agent_metrics): what each collaborator
| did in a conversation, derived from its events and messages.
*/

beforeEach(function () {
    // Monday 2026-10-19 10:00 in Mexico City.
    $this->start = CarbonImmutable::parse('2026-10-19 10:00', 'America/Mexico_City')->utc();
    $this->travelTo($this->start);
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->other = Department::factory()->create(['name' => 'Soporte']);
    $this->supervisor = User::factory()->administrator()->create(['name' => 'Sofía Supervisora']);
    $this->ana = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->beto = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Beto']);
    $this->connection = Connection::factory()->operational()->create();
});

/**
 * A message of the conversation at the current (travelled) time, announced
 * like the messaging pipeline does.
 */
function attributionMessage(Conversation $conversation, MessageDirection $direction, ?User $sender = null, MessageStatus $status = MessageStatus::Sent, array $attributes = []): Message
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => $direction,
        'status' => $direction === MessageDirection::Inbound ? MessageStatus::Received : $status,
        'sender_user_id' => $sender?->id,
        ...$attributes,
    ]);

    MessageRecorded::dispatch($message->id, $conversation->id, $direction->value);

    return $message;
}

/**
 * Stored rows of a conversation keyed by user id, without the bookkeeping columns.
 *
 * @return array<int, array<string, mixed>>
 */
function attributionRows(Conversation $conversation): array
{
    return ConversationAgentMetric::query()
        ->where('conversation_id', $conversation->id)
        ->orderBy('user_id')
        ->get()
        ->mapWithKeys(fn (ConversationAgentMetric $row): array => [$row->user_id => collect($row->getAttributes())->except(['id', 'computed_at'])->all()])
        ->all();
}

function attributionFilters(User $viewer, array $input = ['period' => '7d']): AnalyticsFilters
{
    return AnalyticsFilters::from($input, $viewer, app(AnalyticsScope::class), 'America/Mexico_City');
}

/**
 * The reference timeline: Ana accepts and answers, a supervisor replies
 * without being assigned, Ana transfers to Beto, Beto closes, it is reopened
 * (back to Beto), Beto transfers back to Ana and Ana closes again.
 */
function attributionTimeline(): Conversation
{
    $test = test();
    $start = $test->start;
    $conversation = Conversation::factory()->for($test->connection, 'channelConnection')->inDepartment($test->department)->create();
    attributionMessage($conversation, MessageDirection::Inbound);

    $test->travelTo($start->addMinutes(5));
    app(AcceptConversation::class)->handle($conversation, $test->ana);

    $test->travelTo($start->addMinutes(8));
    attributionMessage($conversation, MessageDirection::Outbound, $test->ana);

    $test->travelTo($start->addMinutes(10));
    attributionMessage($conversation, MessageDirection::Outbound, $test->supervisor);

    $test->travelTo($start->addMinutes(20));
    app(TransferConversation::class)->handle($conversation->refresh(), $test->department, $test->beto, $test->ana);

    $test->travelTo($start->addMinutes(25));
    attributionMessage($conversation, MessageDirection::Outbound, $test->beto);
    attributionMessage($conversation, MessageDirection::Outbound, $test->beto);
    // Neither an automatic message nor a failed one is a reply of the person.
    attributionMessage($conversation, MessageDirection::Outbound, $test->beto, MessageStatus::Sent, ['metadata' => ['automatic' => true]]);
    attributionMessage($conversation, MessageDirection::Outbound, $test->beto, MessageStatus::Failed);

    $test->travelTo($start->addMinutes(30));
    app(CloseConversation::class)->handle($conversation->refresh(), $test->beto);

    $test->travelTo($start->addMinutes(40));
    app(ReopenConversation::class)->handle($conversation->refresh(), $test->supervisor);

    $test->travelTo($start->addMinutes(45));
    app(TransferConversation::class)->handle($conversation->refresh(), $test->department, $test->ana, $test->beto);

    $test->travelTo($start->addMinutes(50));
    app(CloseConversation::class)->handle($conversation->refresh(), $test->ana);

    return $conversation->refresh();
}

test('a transfer, a supervisor reply and a reopening are attributed to who did each thing', function () {
    $conversation = attributionTimeline();
    $rows = ConversationAgentMetric::query()->where('conversation_id', $conversation->id)->get()->keyBy('user_id');

    expect($rows)->toHaveCount(3);

    expect($rows[$this->ana->id])
        ->was_responsible->toBeTrue()
        ->responsible_now->toBeFalse()
        // 5→20 and 45→50 minutes.
        ->handling_seconds->toBe(1200)
        ->intervals_count->toBe(2)
        ->open_since->toBeNull()
        ->replies_count->toBe(1)
        ->authored_first_response->toBeTrue()
        ->first_response_seconds->toBe(480)
        // Only the second closing happened while Ana was responsible.
        ->closes_count->toBe(1)
        ->transfers_out->toBe(1)
        ->transfers_in->toBe(1)
        ->and($rows[$this->ana->id]->attended_at->equalTo($this->start->addMinutes(5)))->toBeTrue();

    expect($rows[$this->beto->id])
        ->was_responsible->toBeTrue()
        // 20→30 and 40→45 minutes: the time it stayed closed is nobody's.
        ->handling_seconds->toBe(900)
        ->intervals_count->toBe(2)
        ->replies_count->toBe(2)
        ->authored_first_response->toBeFalse()
        ->first_response_seconds->toBeNull()
        ->closes_count->toBe(1)
        ->transfers_in->toBe(1)
        ->transfers_out->toBe(1)
        ->and($rows[$this->beto->id]->attended_at->equalTo($this->start->addMinutes(20)))->toBeTrue();

    // The supervisor never was responsible: she counts through her reply.
    expect($rows[$this->supervisor->id])
        ->was_responsible->toBeFalse()
        ->handling_seconds->toBe(0)
        ->intervals_count->toBe(0)
        ->replies_count->toBe(1)
        ->closes_count->toBe(0)
        ->and($rows[$this->supervisor->id]->attended_at->equalTo($this->start->addMinutes(10)))->toBeTrue();
});

test('rebuilding the rows is idempotent and matches what the events produced incrementally', function () {
    $conversation = attributionTimeline();
    $incremental = attributionRows($conversation);

    ConversationAgentMetric::query()->delete();
    app(RebuildConversationMetrics::class)->handle();
    $rebuilt = attributionRows($conversation);

    app(RebuildConversationMetrics::class)->handle();

    expect($rebuilt)->toEqual($incremental)
        ->and(attributionRows($conversation))->toEqual($incremental)
        ->and(ConversationAgentMetric::query()->count())->toBe(3);
});

test('a person who leaves the history loses the row on the next rebuild', function () {
    $conversation = attributionTimeline();
    Message::query()->where('conversation_id', $conversation->id)->where('sender_user_id', $this->supervisor->id)->delete();

    app(RebuildConversationMetrics::class)->handle();

    expect(array_keys(attributionRows($conversation)))->toEqualCanonicalizing([$this->ana->id, $this->beto->id]);
});

test('the automatic distribution makes the agent responsible but is not attention, and a running interval is never frozen', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->ana)->create();
    DB::table('conversation_events')->insert([
        'conversation_id' => $conversation->id,
        'type' => ConversationEventType::AutoAssigned->value,
        'to_user_id' => $this->ana->id,
        'created_at' => $this->start->addMinutes(2),
    ]);

    $this->travelTo($this->start->addHours(3));
    app(RebuildConversationMetrics::class)->handle();

    $row = ConversationAgentMetric::query()->where('conversation_id', $conversation->id)->sole();

    expect($row)
        ->user_id->toBe($this->ana->id)
        ->was_responsible->toBeTrue()
        ->responsible_now->toBeTrue()
        ->attended_at->toBeNull()
        ->handling_seconds->toBe(0)
        ->intervals_count->toBe(1)
        ->and($row->open_since->equalTo($this->start->addMinutes(2)))->toBeTrue();

    $figures = collect(app(AgentProductivity::class)->rows($this->supervisor, attributionFilters($this->supervisor)))->firstWhere('id', $this->ana->id);

    // Assigned, not attended; the unfinished interval is not averaged (never a 0 s average).
    expect($figures)
        ->assigned->toBe(1)
        ->attended->toBe(0)
        ->handling_seconds->toBeNull()
        ->handling_samples->toBe(0)
        ->pending_now->toBe(1);
});

test('an agent who moves their own conversation to another department is not counted as a transfer in or out', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();

    $this->travelTo($this->start->addMinutes(5));
    app(AcceptConversation::class)->handle($conversation, $this->ana);

    // Ana sends it to Soporte and stays responsible: one unbroken interval.
    DB::table('conversation_events')->insert([
        'conversation_id' => $conversation->id,
        'type' => ConversationEventType::Transferred->value,
        'actor_user_id' => $this->ana->id,
        'from_user_id' => $this->ana->id,
        'to_user_id' => $this->ana->id,
        'from_department_id' => $this->department->id,
        'to_department_id' => $this->other->id,
        'created_at' => $this->start->addMinutes(15),
    ]);

    $this->travelTo($this->start->addMinutes(30));
    app(CloseConversation::class)->handle($conversation->refresh(), $this->ana);

    expect(ConversationAgentMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->user_id->toBe($this->ana->id)
        ->transfers_out->toBe(0)
        ->transfers_in->toBe(0)
        ->intervals_count->toBe(1)
        // 5→30 minutes.
        ->handling_seconds->toBe(1500)
        ->closes_count->toBe(1);
});

test('an assignee the events do not explain gets a row without a guessed time', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->ana)->create();

    app(RebuildConversationMetrics::class)->handle();

    expect(ConversationAgentMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->user_id->toBe($this->ana->id)
        ->was_responsible->toBeTrue()
        ->responsible_now->toBeTrue()
        ->intervals_count->toBe(0)
        ->open_since->toBeNull()
        ->handling_seconds->toBe(0);
});

test('a conversation attended by two people counts for both but once in the distinct total', function () {
    attributionTimeline();

    $report = app(AgentProductivity::class)->report($this->supervisor, attributionFilters($this->supervisor));
    $rows = collect($report['rows'])->keyBy('id');

    expect($report['conversations'])->toBe(1)
        ->and($rows->sum('attended'))->toBe(3)
        ->and($rows[$this->ana->id])
        ->attended->toBe(1)
        ->closes->toBe(1)
        ->replies->toBe(1)
        ->first_responses->toBe(1)
        ->first_response_seconds->toBe(480)
        ->handling_seconds->toBe(1200)
        ->handling_samples->toBe(1)
        ->and($rows[$this->beto->id])
        ->attended->toBe(1)
        ->closes->toBe(1)
        ->replies->toBe(2)
        // Beto wrote no first response: there is no average, not a 0.
        ->first_response_seconds->toBeNull()
        ->handling_seconds->toBe(900)
        // Each real closing counts once, for whoever was responsible then.
        ->and($rows->sum('closes'))->toBe(2);
});

test('while conversations of the period lack their per-person rows the productivity says so instead of showing zeros', function () {
    // The productivity display and export are phase two (FEATURE_PHASE_TWO).
    config(['features.phase_two' => true]);

    $conversation = attributionTimeline();
    // Nobody worked on this one: it needs no per-person row and never counts as missing.
    Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();
    app(RebuildConversationMetrics::class)->handle();
    $productivity = app(AgentProductivity::class);
    $filters = attributionFilters($this->supervisor);
    $metrics = fn (): array => collect(app(ReportRegistry::class)->find('agent-productivity')->metrics(app(ReportExporter::class)->prepare(app(ReportRegistry::class)->find('agent-productivity'), $this->supervisor, ['period' => '7d'])))
        ->mapWithKeys(fn (Metric $metric): array => [$metric->label => $metric])
        ->all();

    expect($productivity->report($this->supervisor, $filters)['complete'])->toBeTrue()
        ->and($metrics()['Solicitudes distintas atendidas']->value)->toBe(1)
        ->and($metrics()['Finalizadas (cierres)']->value)->toBe(2);

    // As right after the migration: the conversation row exists, the per-person rows were never built.
    DB::table('conversation_agent_metrics')->where('conversation_id', $conversation->id)->delete();

    expect($productivity->report($this->supervisor, $filters))
        ->complete->toBeFalse()
        ->conversations->toBe(0)
        // The exports give no figure («Sin datos») and explain why.
        ->and($metrics()['Solicitudes distintas atendidas']->value)->toBeNull()
        ->and($metrics()['Solicitudes distintas atendidas']->hint)->toBe(AgentProductivity::INCOMPLETE_NOTE)
        ->and($metrics()['Finalizadas (cierres)']->value)->toBeNull()
        ->and($metrics()['Finalizadas (cierres)']->hint)->toBe(AgentProductivity::INCOMPLETE_NOTE)
        // A period without those conversations is not affected.
        ->and($productivity->complete(attributionFilters($this->supervisor, ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'])))->toBeTrue();

    $this->actingAs($this->supervisor)->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('analytics.productivity.complete', false)));

    app(RebuildConversationMetrics::class)->handle();

    expect($productivity->report($this->supervisor, $filters)['complete'])->toBeTrue();
});

test('a personal reach only sees its own row and a departments reach only its departments', function () {
    attributionTimeline();
    $foreign = User::factory()->agent()->available()->inDepartments($this->other)->create(['name' => 'Carla']);
    $elsewhere = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->other)->create();
    app(AcceptConversation::class)->handle($elsewhere, $foreign);
    $own = User::factory()->withRole(Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewReports, Permission::ViewConversations])->create())->create();
    $lead = User::factory()->supervisor()->inDepartments($this->other)->create();
    $productivity = app(AgentProductivity::class);

    $ana = $this->ana->refresh();
    $anaRows = $productivity->rows($ana, attributionFilters($ana));

    expect(array_column($anaRows, 'name'))->toBe(['Ana'])
        ->and($productivity->rows($own, attributionFilters($own)))->toBe([])
        ->and(array_column($productivity->rows($lead, attributionFilters($lead)), 'name'))->toBe(['Carla'])
        ->and($productivity->report($ana, attributionFilters($ana))['workload']['unassigned'])->toBe(0);
});
