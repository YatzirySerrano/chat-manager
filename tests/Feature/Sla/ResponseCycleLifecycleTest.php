<?php

use App\Actions\Conversations\AssignConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\CloseInactiveConversations;
use App\Actions\Conversations\QueueOutboundMessage;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Templates\QueueTemplateMessage;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\ResponseCycleKind;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationCategory;
use App\Models\Department;
use App\Models\Message;
use App\Models\ResponseCycle;
use App\Models\User;
use App\Services\Analytics\BusinessHoursCalendar;
use App\Services\Conversations\ResponseClock;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Templates\TemplateFixtures;
use Tests\Support\WebChatFixtures;

/*
| Response cycles: one row of `response_cycles` per period in which a
| customer waits for a human answer, with the objective in force frozen at
| its start. Only a human reply (or closing the conversation) ends it.
*/

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
    Queue::fake([DeliverOutboundMessage::class]);
    // Monday 2026-11-09 at 10:00 in Mexico City.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 16:00:00', 'UTC'));

    $this->settings = app(PlatformSettings::class);
    $this->settings->update(['sla' => ['first_response_minutes' => 10, 'response_minutes' => 30, 'business_hours_only' => false]]);

    $this->department = Department::factory()->create(['name' => 'Soporte']);
    $this->agent = User::factory()->administrator()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->connection = Connection::factory()->operational()->create(['default_department_id' => $this->department->id]);
});

/**
 * A customer message through the real inbound pipeline, sent "now".
 */
function cycleCustomerWrites(Connection $connection, string $from = '5215512345678'): Conversation
{
    return receiveWhatsapp($connection, $from, 'Hola', CarbonImmutable::now())->conversation;
}

/**
 * The conversation attended by the agent (as the engine leaves it after an
 * assignment), with its open cycle following it.
 */
function cycleAttendedBy(Conversation $conversation, User $agent): Conversation
{
    $conversation->forceFill(['assigned_user_id' => $agent->id, 'assigned_at' => now(), 'status' => ConversationStatus::Open])->save();
    app(ResponseClock::class)->sync($conversation);

    return $conversation;
}

function cycleHumanReply(Conversation $conversation, User $agent): Message
{
    return app(QueueOutboundMessage::class)->handle($conversation, $agent, 'Con gusto le ayudo.', (string) Str::uuid());
}

/**
 * @return Collection<int, ResponseCycle>
 */
function cyclesOf(Conversation $conversation)
{
    return ResponseCycle::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
}

test('a customer message opens one cycle with the objective frozen, and more messages in the same wait open no other', function () {
    $conversation = cycleCustomerWrites($this->connection);

    $this->travel(2)->minutes();
    cycleCustomerWrites($this->connection);
    cycleCustomerWrites($this->connection);

    $cycle = cyclesOf($conversation)->sole();

    expect($cycle)
        ->kind->toBe(ResponseCycleKind::FirstResponse)
        ->active->toBe(1)
        ->connection_id->toBe($this->connection->id)
        ->department_id->toBe($this->department->id)
        ->assigned_user_id->toBeNull()
        ->target_seconds->toBe(600)
        ->business_hours_only->toBeFalse()
        ->and($cycle->started_at->toIso8601String())->toBe('2026-11-09T16:00:00+00:00')
        ->and($cycle->warn_at->toIso8601String())->toBe('2026-11-09T16:08:00+00:00')
        ->and($cycle->due_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00');
});

test('a cycle is recorded without objective when none is configured', function () {
    $this->settings->update(['sla' => ['first_response_minutes' => null]]);

    $cycle = cyclesOf(cycleCustomerWrites($this->connection))->sole();

    expect($cycle)->target_seconds->toBeNull()->warn_at->toBeNull()->due_at->toBeNull()->active->toBe(1);
});

test('a human reply ends the cycle as answered and the next customer message is measured as a later response', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);

    $this->travel(4)->minutes();
    cycleHumanReply($conversation, $this->agent);

    $first = cyclesOf($conversation)->sole();

    expect($first)
        ->active->toBeNull()
        ->outcome->toBe(ResponseCycle::OUTCOME_ANSWERED)
        ->responder_user_id->toBe($this->agent->id)
        ->response_seconds->toBe(240)
        ->business_response_seconds->toBe(240)
        ->breached_at->toBeNull()
        ->exceeded_seconds->toBeNull()
        ->and($first->responded_at->toIso8601String())->toBe('2026-11-09T16:04:00+00:00')
        ->and($first->ended_at->toIso8601String())->toBe('2026-11-09T16:04:00+00:00');

    // A second reply with nobody waiting changes nothing.
    cycleHumanReply($conversation, $this->agent);
    expect(cyclesOf($conversation))->toHaveCount(1);

    $this->travel(1)->minutes();
    cycleCustomerWrites($this->connection);

    $second = cyclesOf($conversation)->last();

    expect(cyclesOf($conversation))->toHaveCount(2)
        ->and($second)
        ->kind->toBe(ResponseCycleKind::Response)
        ->target_seconds->toBe(1800)
        ->assigned_user_id->toBe($this->agent->id)
        ->active->toBe(1);
});

test('a template sent by a person ends the cycle', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    $template = TemplateFixtures::stored($this->connection);

    app(QueueTemplateMessage::class)->handle($conversation, $this->agent, $template, ['header.1' => 'A-100', 'body.1' => 'Ana', 'body.2' => 'lunes', 'button.0.1' => 'A-100'], (string) Str::uuid());

    expect(cyclesOf($conversation)->sole())
        ->outcome->toBe(ResponseCycle::OUTCOME_ANSWERED)
        ->responder_user_id->toBe($this->agent->id);
});

test('automatic messages, integration replies and notes never end the cycle', function () {
    $this->connection->forceFill(['welcome_enabled' => true, 'welcome_message' => 'Hola, gracias por escribirnos.'])->save();
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    ['token' => $token] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);

    app(QueueOutboundMessage::class)->handleForIntegration($conversation, $token, 'Su pedido va en camino', 'pedido-1');
    $this->actingAs($this->agent)->post(route('conversations.notes.store', $conversation), ['body' => 'Llamar mañana.']);
    $this->actingAs($this->agent)->post(route('conversations.read', $conversation));

    expect(Message::query()->where('conversation_id', $conversation->id)->where('metadata->automatic', true)->exists())->toBeTrue()
        ->and(cyclesOf($conversation)->sole())->active->toBe(1)->outcome->toBeNull();
});

test('closing without a human answer ends the cycle as closed, neither met nor breached before its deadline', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);

    $this->travel(3)->minutes();
    app(CloseConversation::class)->handle($conversation, $this->agent);

    expect(cyclesOf($conversation)->sole())
        ->active->toBeNull()
        ->outcome->toBe(ResponseCycle::OUTCOME_CLOSED)
        ->responded_at->toBeNull()
        ->response_seconds->toBeNull()
        ->breached_at->toBeNull();
});

test('the automatic close by inactivity ends the cycle and records the breach it finds', function () {
    $this->settings->update(['operations' => ['auto_close_after_hours' => 1]]);
    $conversation = cycleCustomerWrites($this->connection);

    $this->travel(2)->hours();
    app(CloseInactiveConversations::class)->handle();

    $cycle = cyclesOf($conversation)->sole();

    expect($cycle)
        ->outcome->toBe(ResponseCycle::OUTCOME_CLOSED)
        ->exceeded_seconds->toBe(110 * 60)
        ->breach_user_id->toBeNull()
        ->and($cycle->breached_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00');
});

test('a reopened conversation starts a fresh cycle on the next customer message', function () {
    $this->connection->forceFill(['reopen_window_minutes' => 60])->save();
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    cycleHumanReply($conversation, $this->agent);
    app(CloseConversation::class)->handle($conversation, $this->agent);

    $this->travel(5)->minutes();
    $reopened = cycleCustomerWrites($this->connection);

    $cycles = cyclesOf($conversation);

    expect($reopened->id)->toBe($conversation->id)
        ->and($cycles)->toHaveCount(2)
        ->and($cycles->last())
        ->kind->toBe(ResponseCycleKind::Response)
        ->active->toBe(1)
        ->and($cycles->last()->started_at->toIso8601String())->toBe('2026-11-09T16:05:00+00:00');
});

test('imported conversations never get a cycle', function () {
    $imported = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id]);
    $imported->forceFill(['origin' => collect(ConversationOrigin::cases())->first(fn (ConversationOrigin $origin): bool => $origin !== ConversationOrigin::Live)])->save();

    expect(app(ResponseClock::class)->customerWrote($imported->refresh(), now()))->toBeNull()
        ->and(ResponseCycle::query()->count())->toBe(0);
});

test('a customer message delivered late, sent before the latest answer, is not a new wait', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    $this->travel(2)->minutes();
    cycleHumanReply($conversation, $this->agent);

    receiveWhatsapp($this->connection, '5215512345678', 'Mensaje atrasado', CarbonImmutable::now()->subMinute());

    expect(cyclesOf($conversation))->toHaveCount(1);
});

test('changing the objective never moves a cycle that already started; the next cycle uses the new value', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);

    $this->settings->update(['sla' => ['first_response_minutes' => 60, 'response_minutes' => 5]]);
    $this->department->forceFill(['service_rules' => ['sla.first_response_minutes' => 90]])->save();

    $first = cyclesOf($conversation)->sole();
    expect($first->target_seconds)->toBe(600)
        ->and($first->due_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00');

    cycleHumanReply($conversation, $this->agent);
    $this->travel(1)->minutes();
    cycleCustomerWrites($this->connection);

    expect(cyclesOf($conversation)->last())
        ->target_seconds->toBe(300)
        ->and(cyclesOf($conversation)->first()->target_seconds)->toBe(600);
});

test('the objective of the category wins over the department, and the department over the platform', function () {
    $this->department->forceFill(['service_rules' => ['sla.first_response_minutes' => 20, 'sla.warning_percent' => 50]])->save();
    $category = ConversationCategory::factory()->create(['service_rules' => ['sla.first_response_minutes' => 5]]);

    $byDepartment = cyclesOf(cycleCustomerWrites($this->connection))->sole();

    $categorized = Conversation::factory()->inDepartment($this->department)->create(['connection_id' => $this->connection->id]);
    $categorized->forceFill(['category_id' => $category->id])->save();
    $byCategory = app(ResponseClock::class)->customerWrote($categorized, now());

    expect($byDepartment->target_seconds)->toBe(1200)
        ->and($byDepartment->warn_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00')
        ->and($byCategory)
        ->target_seconds->toBe(300)
        ->category_id->toBe($category->id)
        // The warning percent still comes from the department.
        ->and($byCategory->warn_at->toIso8601String())->toBe('2026-11-09T16:02:30+00:00');
});

test('with business hours only, the deadline counts the department schedule', function (string $startsAt, string $warnAt, string $dueAt) {
    // Default schedule: Monday to Friday, 09:00 to 18:00 (Mexico City).
    $this->settings->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true, 'holidays' => [['date' => '2026-11-16', 'label' => 'Día festivo', 'recurring' => false]]],
        'sla' => ['first_response_minutes' => 60, 'business_hours_only' => true],
    ]);
    $this->travelTo(CarbonImmutable::parse($startsAt, 'America/Mexico_City'));

    $cycle = cyclesOf(cycleCustomerWrites($this->connection))->sole();
    $local = fn (CarbonImmutable $moment): string => $moment->setTimezone('America/Mexico_City')->format('Y-m-d H:i');

    expect($cycle->business_hours_only)->toBeTrue()
        ->and($local($cycle->warn_at))->toBe($warnAt)
        ->and($local($cycle->due_at))->toBe($dueAt);
})->with([
    'inside the schedule' => ['2026-11-09 10:00', '2026-11-09 10:48', '2026-11-09 11:00'],
    'outside the schedule: counts from the next opening' => ['2026-11-09 20:00', '2026-11-10 09:48', '2026-11-10 10:00'],
    'weekend' => ['2026-11-14 12:00', '2026-11-17 09:48', '2026-11-17 10:00'],
    'holiday' => ['2026-11-16 11:00', '2026-11-17 09:48', '2026-11-17 10:00'],
    'across the closing time' => ['2026-11-09 17:30', '2026-11-10 09:18', '2026-11-10 09:30'],
]);

test('with calendar time the deadline ignores the schedule, and the business time of the answer is still measured', function () {
    $this->settings->update([
        'general' => ['timezone' => 'America/Mexico_City'],
        'business_hours' => ['enabled' => true],
        'sla' => ['first_response_minutes' => 60, 'business_hours_only' => false],
    ]);
    // Monday 17:30 local: 30 business minutes left today.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 17:30', 'America/Mexico_City'));

    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    $this->travel(45)->minutes();
    cycleHumanReply($conversation, $this->agent);

    $cycle = cyclesOf($conversation)->sole();

    expect($cycle->due_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i'))->toBe('2026-11-09 18:30')
        ->and($cycle)
        ->response_seconds->toBe(45 * 60)
        ->business_response_seconds->toBe(30 * 60)
        ->breached_at->toBeNull();
});

test('a late answer is recorded as a breach even when the evaluator never ran', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);

    $this->travel(25)->minutes();
    cycleHumanReply($conversation, $this->agent);

    $cycle = cyclesOf($conversation)->sole();

    expect($cycle)
        ->outcome->toBe(ResponseCycle::OUTCOME_ANSWERED)
        ->breach_user_id->toBe($this->agent->id)
        ->exceeded_seconds->toBe(15 * 60)
        ->and($cycle->breached_at->toIso8601String())->toBe('2026-11-09T16:10:00+00:00');
});

test('an overrun in business time that cannot be computed is stored as unknown, never in calendar seconds', function () {
    $this->settings->update(['general' => ['timezone' => 'America/Mexico_City'], 'business_hours' => ['enabled' => true]]);
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    // An objective in attention hours whose deadline passed longer ago than the calendar walks.
    $dueAt = CarbonImmutable::now()->subDays(BusinessHoursCalendar::MAX_DAYS + 30);
    ResponseCycle::query()->where('conversation_id', $conversation->id)->update([
        'business_hours_only' => true,
        'started_at' => $dueAt->subHour(),
        'due_at' => $dueAt,
    ]);
    $open = cyclesOf($conversation)->sole();

    expect(app(ResponseClock::class)->exceededSeconds($open, CarbonImmutable::now(), null))->toBeNull();

    cycleHumanReply($conversation, $this->agent);

    expect(cyclesOf($conversation)->sole())
        ->outcome->toBe(ResponseCycle::OUTCOME_ANSWERED)
        ->breached_at->not->toBeNull()
        ->exceeded_seconds->toBeNull();
});

test('an answer exactly at the deadline meets the objective', function () {
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);

    $this->travel(10)->minutes();
    cycleHumanReply($conversation, $this->agent);

    expect(cyclesOf($conversation)->sole())->breached_at->toBeNull()->exceeded_seconds->toBeNull();
});

test('reassigning or transferring keeps the clock and moves the responsibility', function () {
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $beto = User::factory()->administrator()->inDepartments([$this->department, $sales])->create(['name' => 'Beto']);
    $conversation = cycleAttendedBy(cycleCustomerWrites($this->connection), $this->agent);
    $before = cyclesOf($conversation)->sole();

    $this->travel(3)->minutes();
    app(AssignConversation::class)->handle($conversation, $beto, $this->agent);

    $assigned = cyclesOf($conversation)->sole();

    expect($assigned)
        ->assigned_user_id->toBe($beto->id)
        ->active->toBe(1)
        ->and($assigned->started_at->equalTo($before->started_at))->toBeTrue()
        ->and($assigned->due_at->equalTo($before->due_at))->toBeTrue();

    app(TransferConversation::class)->handle($conversation->refresh(), $sales, null, $beto, 'Corresponde a ventas');

    $transferred = cyclesOf($conversation)->sole();

    expect($transferred)
        ->department_id->toBe($sales->id)
        ->assigned_user_id->toBeNull()
        ->target_seconds->toBe(600)
        ->and($transferred->due_at->equalTo($before->due_at))->toBeTrue();
});
