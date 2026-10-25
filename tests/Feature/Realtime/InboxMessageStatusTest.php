<?php

use App\Actions\Messaging\RecordMessageStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\ProviderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/*
| The inbox list follows the delivery status of each row's latest message
| through a lightweight `inbox.message_status` signal, sent to the same
| audience as inbox.changed and only for the latest message.
*/

beforeEach(function () {
    // Any broadcaster other than "null" enables the real-time listeners.
    config(['broadcasting.default' => 'log']);

    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->agent)->create();
});

/**
 * @return Collection<int, RealtimeSignal>
 */
function inboxStatusSignals(): Collection
{
    return Event::dispatched(RealtimeSignal::class)
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->where('event', 'inbox.message_status')
        ->values();
}

function outboundMessage(Conversation $conversation, MessageStatus $status, array $attributes = []): Message
{
    return Message::factory()->for($conversation)->create([
        'direction' => MessageDirection::Outbound,
        'status' => $status,
        ...$attributes,
    ]);
}

test('a status change of the latest message reaches every inbox that lists the conversation', function () {
    $message = outboundMessage($this->conversation, MessageStatus::Delivered);
    Event::fake([RealtimeSignal::class]);

    MessageStatusChanged::dispatch($message->id, $this->conversation->id, 'delivered', 'sent');

    $signals = inboxStatusSignals();

    expect($signals)->toHaveCount(1)
        ->and($signals->first()->channels)->toEqualCanonicalizing([
            'inbox.global',
            "inbox.departments.{$this->department->id}",
            "users.{$this->agent->id}",
        ])
        ->and($signals->first()->payload)->toBe([
            'conversation_id' => $this->conversation->id,
            'message_id' => $message->id,
            'status' => 'delivered',
            'error_code' => null,
            'updated_at' => $message->fresh()->updated_at->toIso8601String(),
        ]);
});

test('a failure of unknown outcome carries its code so the list tells it apart', function () {
    $message = outboundMessage($this->conversation, MessageStatus::Failed, ['error_code' => 'delivery_unknown']);
    Event::fake([RealtimeSignal::class]);

    MessageStatusChanged::dispatch($message->id, $this->conversation->id, 'failed', 'sending');

    expect(inboxStatusSignals()->first()->payload['error_code'])->toBe('delivery_unknown');
});

test('status changes of older messages never reach the lists', function () {
    $older = outboundMessage($this->conversation, MessageStatus::Read);
    outboundMessage($this->conversation, MessageStatus::Sending);
    Event::fake([RealtimeSignal::class]);

    MessageStatusChanged::dispatch($older->id, $this->conversation->id, 'read', 'delivered');

    expect(inboxStatusSignals())->toBeEmpty()
        ->and(Event::dispatched(RealtimeSignal::class)->map(fn (array $arguments) => $arguments[0])->where('event', 'message.status'))->toHaveCount(1);
});

test('a pending conversation also reaches its department queue, so every viewer of the list gets it', function () {
    $pending = Conversation::factory()->inDepartment($this->department)->create();
    $message = outboundMessage($pending, MessageStatus::Accepted);
    Event::fake([RealtimeSignal::class]);

    MessageStatusChanged::dispatch($message->id, $pending->id, 'accepted', 'sending');

    expect(inboxStatusSignals()->first()->channels)->toEqualCanonicalizing([
        'inbox.global',
        "inbox.departments.{$this->department->id}",
        "inbox.queues.{$this->department->id}",
    ]);
});

test('duplicate and out of order webhooks never move the status back nor signal again', function () {
    $message = outboundMessage($this->conversation, MessageStatus::Accepted, ['whatsapp_message_id' => 'wamid.LIST1']);
    $connection = $this->conversation->channelConnection;
    $record = fn (string $status) => app(RecordMessageStatus::class)->handle($connection, new ProviderStatus(
        phoneNumberId: (string) $connection->phone_number_id,
        messageId: 'wamid.LIST1',
        status: $status,
        occurredAt: CarbonImmutable::now(),
    ));
    Event::fake([RealtimeSignal::class]);

    $record('read');
    $record('read');
    $record('delivered');
    $record('sent');

    expect($message->fresh()->status)->toBe(MessageStatus::Read)
        ->and(inboxStatusSignals()->pluck('payload.status')->all())->toBe(['read']);
});
