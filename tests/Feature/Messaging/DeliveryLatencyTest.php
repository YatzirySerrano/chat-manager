<?php

use App\Broadcasting\RealtimeSignals;
use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Conversations\ConversationChanged;
use App\Events\Messaging\MessageRecorded;
use App\Events\Messaging\MessageStatusChanged;
use App\Events\TeamChat\Broadcasts\TeamChatUserSignal;
use App\Events\TeamChat\Broadcasts\TeamThreadSignal;
use App\Jobs\DeliverOutboundMessage;
use App\Listeners\WebChat\SignalVisitorSessions;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Operations\QueueMonitor;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppFixtures;

/*
| Latency of the message path: a finished delivery releases the next reply
| of its conversation at once (never twice, never out of order), an
| ambiguous earlier reply does not block the conversation, uploads run on
| the media queue, inbound processing records its timing and the real-time
| fan-out skips work nobody needs. Every Graph call is faked.
*/

const LATENCY_TOKEN = 'EAAGlatencyTestToken000000000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00:00', 'UTC'));

    $department = Department::factory()->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '140000000000088', 'access_token' => LATENCY_TOKEN]);
    $this->messagesUrl = 'https://graph.facebook.com/v26.0/140000000000088/messages';
    $agent = User::factory()->agent()->available()->inDepartments($department)->create();
    $this->conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'connection_id' => $this->connection->id,
        'status' => ConversationStatus::Open,
        'last_inbound_at' => now()->subMinutes(5),
    ]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '14155550199']);
    $this->acceptEverything = fn () => Http::fake([$this->messagesUrl => fn (Request $request) => Http::response([
        'messaging_product' => 'whatsapp',
        'messages' => [['id' => 'wamid.'.$request['biz_opaque_callback_data']]],
    ])]);
});

/**
 * Runs the delivery job as a queue worker would (an asynchronous queue job).
 */
function latencyDeliver(DeliverOutboundMessage $job): DeliverOutboundMessage
{
    app()->call([$job->withFakeQueueInteractions(), 'handle']);

    return $job;
}

/**
 * Jobs pushed for one message.
 *
 * @return list<DeliverOutboundMessage>
 */
function latencyPushedFor(Message $message): array
{
    return Queue::pushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $job): bool => $job->messageId === $message->id)->values()->all();
}

/**
 * An outbound file message of the conversation with its stored attachment.
 *
 * @param  array<string, mixed>  $attachment
 */
function latencyMediaMessage(Conversation $conversation, array $attachment = []): Message
{
    $message = WhatsAppFixtures::outbound($conversation, ['type' => 'image', 'body' => null]);
    $record = new MessageAttachment;
    $record->forceFill([
        'message_id' => $message->id,
        'disk' => 'local',
        'path' => 'media/2026/10/latency',
        'mime_type' => 'image/png',
        'file_name' => 'foto.png',
        'size_bytes' => 68,
        'status' => AttachmentStatus::Available,
        'delivery_method' => AttachmentDeliveryMethod::Native,
        ...$attachment,
    ])->save();

    return $message;
}

test('an accepted reply releases the next queued reply of its conversation at once, which then leaves exactly once', function () {
    ($this->acceptEverything)();
    Queue::fake();
    $first = WhatsAppFixtures::outbound($this->conversation, ['body' => 'Uno']);
    $second = WhatsAppFixtures::outbound($this->conversation, ['body' => 'Dos']);

    // The second reply's own job came first and waits for the first one.
    latencyDeliver(new DeliverOutboundMessage($second->id));
    latencyDeliver(new DeliverOutboundMessage($first->id));

    $released = collect(latencyPushedFor($second))->firstWhere('releasedByPrevious', true);

    expect($released)->not->toBeNull()
        ->and($released->delay)->toBeNull()
        ->and($released->queue)->toBe(Queues::name(Queues::MESSAGES));

    latencyDeliver($released);
    // The wait check of the second reply's own job arrives afterwards.
    latencyDeliver(collect(latencyPushedFor($second))->firstWhere('releasedByPrevious', false));

    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]['text']['body'])->all())->toBe(['Uno', 'Dos'])
        ->and($second->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($second->delivery_attempts)->toBe(1)
        ->and($second->metadata['delivery']['timing']['released_by_previous'])->toBe(1);
});

test('a reply that failed or was refused also releases the next one', function (Closure $fake, array $attributes) {
    $fake->call($this);
    Queue::fake();
    $first = WhatsAppFixtures::outbound($this->conversation, $attributes);
    $second = WhatsAppFixtures::outbound($this->conversation);

    latencyDeliver(new DeliverOutboundMessage($first->id));

    expect($first->refresh()->status)->toBe(MessageStatus::Failed)
        ->and(collect(latencyPushedFor($second))->where('releasedByPrevious', true))->toHaveCount(1);
})->with([
    'refused by Meta' => [fn () => Http::fake([$this->messagesUrl => Http::response(['error' => ['message' => 'Recipient not on WhatsApp', 'code' => 131026]], 400)]), []],
    'blocked before reaching Meta' => [fn () => Http::fake(), ['type' => 'template', 'metadata' => ['template' => ['id' => 999_999]]]],
]);

test('a reply released by the previous one that still has to wait never starts a second wait chain', function () {
    Http::fake();
    Queue::fake();
    WhatsAppFixtures::outbound($this->conversation);
    $next = WhatsAppFixtures::outbound($this->conversation);
    $released = new DeliverOutboundMessage($next->id);
    $released->releasedByPrevious = true;

    latencyDeliver($released);

    expect($next->refresh()->status)->toBe(MessageStatus::Queued);
    Queue::assertNotPushed(DeliverOutboundMessage::class);
    Http::assertNothingSent();
});

test('an earlier file still being uploaded holds the next reply for as long as one attempt may take', function () {
    Http::fake();
    Queue::fake();
    $file = latencyMediaMessage($this->conversation);
    $file->forceFill(['status' => MessageStatus::Sending, 'sending_started_at' => now()->subSeconds(DeliverOutboundMessage::textSendingHold() + 30)])->save();
    $next = WhatsAppFixtures::outbound($this->conversation);

    latencyDeliver(new DeliverOutboundMessage($next->id));

    expect($next->refresh()->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();
});

test('a message put back in the queue after a retryable error is claimed only once its backoff ended', function () {
    ($this->acceptEverything)();
    Queue::fake();
    $message = WhatsAppFixtures::outbound($this->conversation, ['metadata' => ['delivery' => ['retry_not_before' => now()->addSeconds(10)->toIso8601String()]]]);
    $early = new DeliverOutboundMessage($message->id);
    $early->releasedByPrevious = true;

    latencyDeliver($early);

    expect($message->refresh()->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();

    $this->travel(10)->seconds();
    latencyDeliver(new DeliverOutboundMessage($message->id));

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($message->metadata['delivery'])->not->toHaveKey('retry_not_before');
});

test('a retryable provider error records until when the message waits', function () {
    Http::fake([$this->messagesUrl => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503)]);
    Queue::fake();
    $message = WhatsAppFixtures::outbound($this->conversation);

    latencyDeliver(new DeliverOutboundMessage($message->id));

    expect($message->refresh()->status)->toBe(MessageStatus::Queued)
        ->and(CarbonImmutable::parse($message->metadata['delivery']['retry_not_before'])->equalTo(now()->addSeconds(10)))->toBeTrue();
});

test('only deliveries that upload a file run on the media queue', function (Closure $make, string $queue) {
    $message = $make->call($this);

    expect((new DeliverOutboundMessage($message->id))->queue)->toBe(Queues::name($queue));
})->with([
    'an image not uploaded yet' => [fn () => latencyMediaMessage($this->conversation), Queues::MEDIA],
    'an image already uploaded' => [fn () => latencyMediaMessage($this->conversation, ['whatsapp_media_id' => '1234567890']), Queues::MESSAGES],
    'a file sent as a download link' => [fn () => latencyMediaMessage($this->conversation, ['delivery_method' => AttachmentDeliveryMethod::Link]), Queues::MESSAGES],
    'a text' => [fn () => WhatsAppFixtures::outbound($this->conversation), Queues::MESSAGES],
    'a file on the web chat (nothing is uploaded)' => [fn () => latencyMediaMessage(Conversation::factory()->create(['connection_id' => Connection::factory()->webChat()->create()->id])), Queues::MESSAGES],
]);

test('a processed webhook records its timing without content', function () {
    $at = now()->subSeconds(3);

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Mensaje privado', at: CarbonImmutable::parse($at))]))->assertOk();

    $timing = WebhookEvent::query()->sole()->summary['timing'];

    expect(array_keys($timing))->toEqualCanonicalizing(['meta_occurred_at', 'received_at', 'job_started_at', 'processed_at', 'meta_to_received_ms', 'queue_wait_ms', 'processing_ms', 'attempt'])
        ->and($timing['meta_occurred_at'])->toBe('2026-10-22T17:59:57.000Z')
        ->and($timing['meta_to_received_ms'])->toBe(3000)
        ->and($timing['attempt'])->toBe(1)
        ->and((string) json_encode($timing))->not->toContain('Mensaje privado')->not->toContain('5215512345678');
});

test('the visitor sessions listener only queues the events it can act on', function (object $event, bool $queued) {
    config(['broadcasting.default' => 'log']);

    expect(app(SignalVisitorSessions::class)->shouldQueue($event))->toBe($queued);
})->with([
    'an accepted reply' => [fn () => new MessageStatusChanged(1, 1, 'accepted', 'sending'), true],
    'a reply being sent' => [fn () => new MessageStatusChanged(1, 1, 'sending', 'queued'), false],
    'a reply reported sent' => [fn () => new MessageStatusChanged(1, 1, 'sent', 'accepted'), false],
    'a customer message' => [fn () => new MessageRecorded(1, 1, 'inbound'), true],
    'an agent reply recorded' => [fn () => new MessageRecorded(1, 1, 'outbound'), false],
    'a transfer' => [fn () => new ConversationChanged(1, 'transferred'), true],
    'a tag change' => [fn () => new ConversationChanged(1, 'tags'), false],
]);

test('an inbox change reaches the inbox channels and the people involved in one publication', function () {
    Event::fake([RealtimeSignal::class]);
    config(['broadcasting.default' => 'log']);

    app(RealtimeSignals::class)->inboxChanged($this->conversation, 'message');

    $signals = Event::dispatched(RealtimeSignal::class)->map(fn (array $arguments): RealtimeSignal => $arguments[0])->where('event', 'inbox.changed');

    expect($signals)->toHaveCount(1)
        ->and($signals->first()->channels)->toContain('inbox.global', "users.{$this->conversation->assigned_user_id}");
});

test('a media download tells the open chat which message changed, keeping the previous fields', function () {
    Event::fake([RealtimeSignal::class]);
    config(['broadcasting.default' => 'log']);

    app(RealtimeSignals::class)->conversationChanged($this->conversation, 'message', null, 42);
    app(RealtimeSignals::class)->conversationChanged($this->conversation, 'tags');

    $payloads = Event::dispatched(RealtimeSignal::class)->map(fn (array $arguments): RealtimeSignal => $arguments[0])->where('event', 'conversation.changed')->pluck('payload')->values();

    expect($payloads->all())->toBe([
        ['reason' => 'message', 'version' => $this->conversation->version, 'message_id' => 42],
        ['reason' => 'tags', 'version' => $this->conversation->version],
    ]);
});

test('team chat broadcasts use the real-time queue connection', function () {
    config(['broadcasting.realtime.queue_connection' => 'redis']);

    expect((new TeamThreadSignal(1, 'message.created', ['message_id' => 1]))->connection)->toBe('redis')
        ->and((new TeamChatUserSignal([1], 1, 'message'))->connection)->toBe('redis');

    config(['broadcasting.realtime.queue_connection' => null]);

    expect((new TeamThreadSignal(1, 'message.created', []))->connection)->toBeNull();
});

test('webhooks and outbound messages warn after seconds of backlog, not minutes', function () {
    $monitor = app(QueueMonitor::class);

    expect($monitor->thresholds(Queues::WEBHOOKS))->toMatchArray(['warning_age' => 10, 'critical_age' => 120])
        ->and($monitor->thresholds(Queues::MESSAGES))->toMatchArray(['warning_age' => 10, 'critical_age' => 120])
        ->and($monitor->thresholds(Queues::DEFAULT))->toMatchArray(['warning_age' => 120]);
});
