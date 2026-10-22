<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ReopenConversation;
use App\Actions\Conversations\TransferConversation;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageRecorded;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| Per-conversation metrics, maintained by the analytics listener from the
| domain events and recomputed from the source data.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['name' => 'Ventas']);
    $this->admin = User::factory()->administrator()->create();
    $this->agent = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Ana']);
    $this->colleague = User::factory()->agent()->available()->inDepartments($this->department)->create(['name' => 'Beto']);
    $this->connection = Connection::factory()->operational()->create();
});

/**
 * Store a message of the conversation at the current (travelled) time and
 * announce it like the messaging pipeline does.
 *
 * @param  array<string, mixed>  $attributes
 */
function recordMessage(Conversation $conversation, MessageDirection $direction, MessageStatus|string $status, ?User $sender = null, array $attributes = []): Message
{
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => $direction,
        'status' => $status,
        'sender_user_id' => $sender?->id,
        ...$attributes,
    ]);

    MessageRecorded::dispatch($message->id, $conversation->id, $direction->value);

    return $message;
}

test('a crafted timeline produces exact metrics, excluding automatic, failed and unsent replies', function () {
    app(PlatformSettings::class)->update(['business_hours' => ['enabled' => true]]);
    // Monday 08:00 in Mexico City: before the default 09:00-18:00 schedule.
    $start = CarbonImmutable::parse('2026-10-19 08:00', 'America/Mexico_City')->utc();
    $this->travelTo($start);

    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();
    recordMessage($conversation, MessageDirection::Inbound, MessageStatus::Received);

    $this->travelTo($start->addMinutes(5));
    app(AcceptConversation::class)->handle($conversation, $this->agent);

    $this->travelTo($start->addMinutes(6));
    recordMessage($conversation, MessageDirection::Outbound, MessageStatus::Sent, $this->agent, ['metadata' => ['automatic' => true, 'automatic_kind' => 'welcome']]);

    $this->travelTo($start->addMinutes(7));
    recordMessage($conversation, MessageDirection::Outbound, MessageStatus::Failed, $this->agent);

    $this->travelTo($start->addMinutes(8));
    $pending = recordMessage($conversation, MessageDirection::Outbound, MessageStatus::Queued, $this->agent);

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->first_human_response_at->toBeNull()
        ->seconds_to_first_human_response->toBeNull();

    $this->travelTo($start->addMinutes(10));
    $pending->forceFill(['status' => 'sent'])->save();
    MessageStatusChanged::dispatch($pending->id, $conversation->id, 'sent', 'queued');

    $this->travelTo($start->addMinutes(20));
    app(TransferConversation::class)->handle($conversation->refresh(), $this->department, $this->colleague, $this->agent);

    $this->travelTo($start->addMinutes(30));
    app(CloseConversation::class)->handle($conversation->refresh(), $this->admin);

    $this->travelTo($start->addMinutes(40));
    app(ReopenConversation::class)->handle($conversation->refresh(), $this->admin);

    $this->travelTo($start->addMinutes(50));
    app(CloseConversation::class)->handle($conversation->refresh(), $this->admin);

    $metric = ConversationMetric::query()->where('conversation_id', $conversation->id)->sole();

    expect($metric)
        ->connection_id->toBe($this->connection->id)
        ->department_id->toBe($this->department->id)
        ->agent_id->toBe($this->colleague->id)
        ->status->toBe(ConversationStatus::Closed)
        ->seconds_to_first_attention->toBe(300)
        // The queued reply counts from when it was written (minute 8), once the provider accepted it.
        ->seconds_to_first_human_response->toBe(480)
        ->seconds_to_resolution->toBe(1800)
        ->closed_count->toBe(2)
        ->reopened_count->toBe(1)
        ->transfers_count->toBe(1)
        ->messages_in->toBe(1)
        ->messages_out->toBe(3)
        ->failed_out->toBe(1)
        ->out_of_hours->toBeTrue()
        ->and($metric->resolved_at->equalTo($start->addMinutes(30)))->toBeTrue()
        ->and($metric->last_closed_at->equalTo($start->addMinutes(50)))->toBeTrue();
});

test('without any human reply accepted by the provider there is no response time', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();
    recordMessage($conversation, MessageDirection::Outbound, MessageStatus::Sent, null, ['metadata' => ['automatic' => true]]);
    recordMessage($conversation, MessageDirection::Outbound, MessageStatus::Failed, $this->agent);

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->first_human_response_at->toBeNull()
        ->seconds_to_first_human_response->toBeNull()
        ->seconds_to_first_attention->toBeNull()
        ->seconds_to_resolution->toBeNull();
});

test('out of hours uses the department hours, the platform hours or nothing', function () {
    $settings = app(PlatformSettings::class);
    // Saturday 11:00 local time.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 11:00', 'America/Mexico_City')->utc());
    $custom = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => true, 'start' => '10:00', 'end' => '14:00'])],
    ]);

    $withoutSchedule = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();
    $insideCustom = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($custom)->create();
    $rebuild = app(RebuildConversationMetrics::class);
    $rebuild->handle();

    expect(ConversationMetric::query()->where('conversation_id', $withoutSchedule->id)->value('out_of_hours'))->toBeNull()
        ->and((bool) ConversationMetric::query()->where('conversation_id', $insideCustom->id)->value('out_of_hours'))->toBeFalse();

    $settings->update(['business_hours' => ['enabled' => true]]);
    $rebuild->handle();

    // Saturdays are closed in the default platform schedule.
    expect((bool) ConversationMetric::query()->where('conversation_id', $withoutSchedule->id)->value('out_of_hours'))->toBeTrue();
});

test('a configured non-working day counts as out of hours', function () {
    // Wednesday 11:00 local time: inside the default 09:00-18:00 schedule.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 11:00', 'America/Mexico_City')->utc());
    $settings = app(PlatformSettings::class);
    $settings->update(['business_hours' => ['enabled' => true]]);
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->create();
    $rebuild = app(RebuildConversationMetrics::class);
    $rebuild->handle();

    expect((bool) ConversationMetric::query()->where('conversation_id', $conversation->id)->value('out_of_hours'))->toBeFalse();

    $settings->update(['business_hours' => ['holidays' => [['date' => '2026-10-21', 'label' => 'Día de prueba', 'recurring' => false]]]]);
    $rebuild->handle();

    expect((bool) ConversationMetric::query()->where('conversation_id', $conversation->id)->value('out_of_hours'))->toBeTrue();
});

test('the transfer origin decides the schedule of a transferred conversation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 11:00', 'America/Mexico_City')->utc());
    $support = Department::factory()->create([
        'uses_custom_hours' => true,
        'business_hours' => ['days' => array_fill_keys(PlatformSettings::WEEKDAYS, ['open' => false, 'start' => '09:00', 'end' => '18:00'])],
    ]);
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->agent)->create();

    app(TransferConversation::class)->handle($conversation, $support, null, $this->admin);

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->sole())
        ->department_id->toBe($support->id)
        // Received by Ventas, which has no schedule at all.
        ->out_of_hours->toBeNull();
});

test('the rebuild command is idempotent and restores missing or stale rows', function () {
    $conversations = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->count(3)->create();
    Message::factory()->for($conversations[0])->count(2)->create();

    $this->artisan('analytics:rebuild')->expectsOutputToContain('Métricas recalculadas: 3')->assertSuccessful();
    $first = ConversationMetric::query()->orderBy('conversation_id')->get()->map->only(['conversation_id', 'messages_in', 'status', 'received_at']);

    ConversationMetric::query()->where('conversation_id', $conversations[1]->id)->delete();
    ConversationMetric::query()->where('conversation_id', $conversations[0]->id)->update(['messages_in' => 99]);

    $this->artisan('analytics:rebuild')->assertSuccessful();
    $second = ConversationMetric::query()->orderBy('conversation_id')->get()->map->only(['conversation_id', 'messages_in', 'status', 'received_at']);

    expect(ConversationMetric::query()->count())->toBe(3)
        ->and($second->all())->toEqual($first->all())
        ->and($second->firstWhere('conversation_id', $conversations[0]->id)['messages_in'])->toBe(2);
});

test('the rebuild can be limited to recent or specific conversations and rejects invalid options', function () {
    $old = Conversation::factory()->for($this->connection, 'channelConnection')->create(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
    $recent = Conversation::factory()->for($this->connection, 'channelConnection')->create();

    $this->artisan('analytics:rebuild', ['--days' => 2])->expectsOutputToContain('Métricas recalculadas: 1')->assertSuccessful();
    expect(ConversationMetric::query()->pluck('conversation_id')->all())->toBe([$recent->id]);

    $this->artisan('analytics:rebuild', ['--conversation' => [$old->id]])->expectsOutputToContain('Métricas recalculadas: 1')->assertSuccessful();
    $this->artisan('analytics:rebuild', ['--days' => 'abc'])->assertFailed();

    expect(ConversationMetric::query()->count())->toBe(2);
});

test('notes do not trigger a recomputation', function () {
    $conversation = Conversation::factory()->for($this->connection, 'channelConnection')->inDepartment($this->department)->assignedTo($this->agent)->create();

    $this->actingAs($this->agent)->post(route('conversations.notes.store', $conversation), ['body' => 'Nota interna']);

    expect(ConversationMetric::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});
