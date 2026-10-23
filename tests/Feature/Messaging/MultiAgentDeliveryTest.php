<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppFixtures;

/*
| Several agents attending customers through ONE WhatsApp number: every reply
| goes to the customer of its own conversation, replies of one conversation
| leave in the order they were written without holding back the other
| conversations, and each accepted delivery records how long each stage took.
| Every Graph call is faked.
*/

const MULTI_AGENT_TOKEN = 'EAAGmultiAgentTestToken0000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0']);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00:00', 'UTC'));

    $this->department = Department::factory()->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '140000000000077', 'access_token' => MULTI_AGENT_TOKEN]);
    $this->messagesUrl = 'https://graph.facebook.com/v26.0/140000000000077/messages';
    $this->attended = function (string $waId, ?User $agent = null): Conversation {
        $agent ??= User::factory()->agent()->available()->inDepartments($this->department)->create();
        $conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($agent)->create([
            'connection_id' => $this->connection->id,
            'status' => ConversationStatus::Open,
            'last_inbound_at' => now()->subMinutes(5),
        ]);
        ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'external_id' => $waId]);

        return $conversation;
    };
    $this->acceptEverything = fn () => Http::fake([$this->messagesUrl => fn (Request $request) => Http::response([
        'messaging_product' => 'whatsapp',
        'messages' => [['id' => 'wamid.'.$request['biz_opaque_callback_data']]],
    ])]);
});

/**
 * Runs the delivery job as a queue worker would (an asynchronous queue job).
 */
function deliverFromQueue(DeliverOutboundMessage $job): DeliverOutboundMessage
{
    app()->call([$job->withFakeQueueInteractions(), 'handle']);

    return $job;
}

test('two agents answering two customers through the same number at once each reach their own customer', function () {
    ($this->acceptEverything)();
    $first = ($this->attended)('14155550101');
    $second = ($this->attended)('14155550102');
    [$laura, $mario] = [User::query()->findOrFail($first->assigned_user_id), User::query()->findOrFail($second->assigned_user_id)];
    $references = [(string) Str::uuid(), (string) Str::uuid()];

    $this->actingAs($laura)->postJson(route('conversations.messages.store', $first), ['body' => 'Hola Laura', 'client_reference' => $references[0]])->assertSuccessful();
    $this->actingAs($mario)->postJson(route('conversations.messages.store', $second), ['body' => 'Hola Mario', 'client_reference' => $references[1]])->assertSuccessful();

    $sends = Http::recorded()->map(fn (array $pair): array => [
        'url' => $pair[0]->url(),
        'to' => $pair[0]['to'],
        'body' => $pair[0]['text']['body'],
        'reference' => $pair[0]['biz_opaque_callback_data'],
    ])->all();

    expect($sends)->toBe([
        ['url' => $this->messagesUrl, 'to' => '14155550101', 'body' => 'Hola Laura', 'reference' => $references[0]],
        ['url' => $this->messagesUrl, 'to' => '14155550102', 'body' => 'Hola Mario', 'reference' => $references[1]],
    ])
        ->and(Message::query()->where('client_reference', $references[0])->sole()->conversation_id)->toBe($first->id)
        ->and(Message::query()->where('client_reference', $references[1])->sole()->conversation_id)->toBe($second->id);
});

test('an agent cannot reply in the conversation a colleague of the same number attends', function () {
    Http::fake();
    $first = ($this->attended)('14155550101');
    $second = ($this->attended)('14155550102');

    $status = $this->actingAs(User::query()->findOrFail($first->assigned_user_id))
        ->postJson(route('conversations.messages.store', $second), ['body' => 'No es mía', 'client_reference' => (string) Str::uuid()])
        ->status();

    // Hidden (404) or visible but not theirs to answer (403): refused either way.
    expect($status)->toBeIn([403, 404])
        ->and(Message::query()->where('conversation_id', $second->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('a reply waits for the earlier reply of its conversation and then both leave in the order they were written', function () {
    ($this->acceptEverything)();
    Queue::fake();
    $conversation = ($this->attended)('14155550101');
    $hello = WhatsAppFixtures::outbound($conversation, ['body' => 'Hola']);
    $question = WhatsAppFixtures::outbound($conversation, ['body' => '¿En qué le ayudo?']);

    // The worker that picked the second reply first waits, without using up attempts.
    deliverFromQueue(new DeliverOutboundMessage($question->id));

    expect($question->refresh())
        ->status->toBe(MessageStatus::Queued)
        ->delivery_attempts->toBe(0);
    Http::assertNothingSent();
    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $retry): bool => $retry->messageId === $question->id
        && $retry->delay === 1
        && $retry->orderWaits === 1
        && $retry->queue === Queues::name(Queues::MESSAGES));

    deliverFromQueue(new DeliverOutboundMessage($hello->id));
    $retry = Queue::pushed(DeliverOutboundMessage::class)->first();
    deliverFromQueue($retry);

    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]['text']['body'])->all())->toBe(['Hola', '¿En qué le ayudo?'])
        ->and($question->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($question->delivery_attempts)->toBe(1)
        ->and($question->metadata['delivery']['timing']['order_waits'])->toBe(1);
    Queue::assertPushed(DeliverOutboundMessage::class, 1);
});

test('an earlier reply still on its way holds the next one of its conversation', function (array $earlier) {
    Http::fake();
    Queue::fake();
    $conversation = ($this->attended)('14155550101');
    WhatsAppFixtures::outbound($conversation, $earlier);
    $next = WhatsAppFixtures::outbound($conversation);

    deliverFromQueue(new DeliverOutboundMessage($next->id));

    expect($next->refresh()->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();
    Queue::assertPushed(DeliverOutboundMessage::class, 1);
})->with([
    'queued, waiting for a retry' => [['status' => 'queued']],
    'being sent right now' => [['status' => 'sending', 'sending_started_at' => '2026-10-22 17:59:50']],
]);

test('a reply that keeps waiting for an earlier one keeps holding the replies written after it', function () {
    Http::fake();
    Queue::fake();
    $conversation = ($this->attended)('14155550101');
    WhatsAppFixtures::outbound($conversation);
    $waiting = WhatsAppFixtures::outbound($conversation);
    $last = WhatsAppFixtures::outbound($conversation);
    // It has been waiting behind the first one for longer than the longest backoff.
    Message::query()->whereKey($waiting->id)->update(['updated_at' => now()->subMinutes(10)]);

    deliverFromQueue(new DeliverOutboundMessage($waiting->id));
    deliverFromQueue(new DeliverOutboundMessage($last->id));

    expect($waiting->refresh()->updated_at->equalTo(now()))->toBeTrue()
        ->and($last->refresh()->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();
    Queue::assertPushed(DeliverOutboundMessage::class, 2);
});

test('a long wait for an earlier reply checks again less and less often', function (int $previousWaits, int $delay) {
    Http::fake();
    Queue::fake();
    $conversation = ($this->attended)('14155550101');
    WhatsAppFixtures::outbound($conversation);
    $next = WhatsAppFixtures::outbound($conversation);
    $job = new DeliverOutboundMessage($next->id);
    $job->orderWaits = $previousWaits;

    deliverFromQueue($job);

    Queue::assertPushed(DeliverOutboundMessage::class, fn (DeliverOutboundMessage $retry): bool => $retry->delay === $delay
        && $retry->orderWaits === $previousWaits + 1);
})->with([
    'first wait' => [0, 1],
    'second wait' => [1, 2],
    'third wait' => [2, 4],
    'from then on' => [3, 5],
    'after many waits' => [40, 5],
]);

test('only an earlier reply still expected to leave holds the next one; other conversations of the same number never wait', function (Closure $earlier) {
    ($this->acceptEverything)();
    Queue::fake();
    $conversation = ($this->attended)('14155550101');
    $earlier->call($this, $conversation);
    $next = WhatsAppFixtures::outbound($conversation, ['body' => 'Siguiente']);

    deliverFromQueue(new DeliverOutboundMessage($next->id));

    expect($next->refresh()->status)->toBe(MessageStatus::Accepted);
    Http::assertSentCount(1);
    Queue::assertNotPushed(DeliverOutboundMessage::class);
})->with([
    'queued in another conversation of the same number' => [fn () => WhatsAppFixtures::outbound(($this->attended)('14155550102'))],
    'already accepted by Meta' => [fn (Conversation $conversation) => WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'wamid.earlier'])],
    'failed' => [fn (Conversation $conversation) => WhatsAppFixtures::outbound($conversation, ['status' => 'failed', 'error_code' => 'meta_131026'])],
    'ambiguous for longer than the job timeout (left for reconciliation)' => [fn (Conversation $conversation) => WhatsAppFixtures::outbound($conversation, ['status' => 'sending', 'sending_started_at' => now()->subMinutes(2)])],
    'queued but forgotten for longer than the longest backoff' => [fn (Conversation $conversation) => tap(WhatsAppFixtures::outbound($conversation), fn (Message $message) => Message::query()->whereKey($message->id)->update(['updated_at' => now()->subMinutes(10)]))],
]);

test('without an asynchronous queue replies are delivered as they come', function () {
    ($this->acceptEverything)();
    $conversation = ($this->attended)('14155550101');
    WhatsAppFixtures::outbound($conversation);
    $next = WhatsAppFixtures::outbound($conversation);

    app()->call([new DeliverOutboundMessage($next->id), 'handle']);

    expect($next->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('an accepted delivery records when each stage happened and how long it took, without personal data', function () {
    ($this->acceptEverything)();
    $conversation = ($this->attended)('14155550101');
    $message = WhatsAppFixtures::outbound($conversation, ['body' => 'Texto privado del cliente']);

    deliverFromQueue(new DeliverOutboundMessage($message->id));

    $timing = $message->refresh()->metadata['delivery']['timing'];
    $stamp = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    expect(array_keys($timing))->toEqualCanonicalizing(['queued_at', 'started_at', 'claimed_at', 'graph_request_started_at', 'graph_response_at', 'accepted_at', 'wait_ms', 'claim_ms', 'provider_ms', 'total_ms', 'attempt', 'order_waits'])
        ->and(collect($timing)->only(['queued_at', 'started_at', 'claimed_at', 'graph_request_started_at', 'graph_response_at', 'accepted_at'])->every(fn (string $at): bool => preg_match($stamp, $at) === 1))->toBeTrue()
        ->and(collect($timing)->only(['wait_ms', 'claim_ms', 'provider_ms', 'total_ms'])->every(fn (mixed $ms): bool => is_int($ms) && $ms >= 0))->toBeTrue()
        ->and($timing['queued_at'] <= $timing['claimed_at'] && $timing['claimed_at'] <= $timing['graph_response_at'] && $timing['graph_response_at'] <= $timing['accepted_at'])->toBeTrue()
        ->and($timing['attempt'])->toBe(1)
        ->and($timing['order_waits'])->toBe(0);

    $stored = (string) json_encode($message->metadata);
    expect($stored)->not->toContain('14155550101')
        ->not->toContain('Texto privado')
        ->not->toContain(MULTI_AGENT_TOKEN);
});
