<?php

use App\Enums\AttachmentStatus;
use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Messaging\MessageStatusChanged;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Meta\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;

/*
| Contract tests of the real Cloud API transport and the delivery job:
| every Graph call is faked; the payloads follow Meta's documentation.
*/

const OUTBOUND_TOKEN = 'EAAGoutboundTestToken000000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['whatsapp.transport' => 'meta', 'services.meta.graph_version' => 'v26.0', 'whatsapp.delivery.max_attempts' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));

    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $this->connection = Connection::factory()->verified()->create(['phone_number_id' => '106540352242922', 'access_token' => OUTBOUND_TOKEN]);
    $this->conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'connection_id' => $this->connection->id,
        'last_inbound_at' => now()->subHour(),
    ]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    $this->messagesUrl = 'graph.facebook.com/v26.0/106540352242922/messages';
    $this->deliver = fn (Message $message) => app()->call([new DeliverOutboundMessage($message->id), 'handle']);
});

function acceptedByMeta(string $wamid = 'wamid.HBgLMTY1MDUwNzY1MjAVAgARGBI5QTNDQTVCM0Q0Q0Q2RTY3RTcA'): array
{
    return [
        'messaging_product' => 'whatsapp',
        'contacts' => [['input' => '525511112222', 'wa_id' => '5215511112222']],
        'messages' => [['id' => $wamid]],
    ];
}

test('a text reply is sent with the token in the header and becomes accepted, not delivered', function () {
    Event::fake([MessageStatusChanged::class, ConnectionHealthChanged::class]);
    Http::fake([$this->messagesUrl => Http::response(acceptedByMeta('wamid.text.1'))]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['body' => 'Con gusto le ayudo']);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://'.$this->messagesUrl
        && $request->hasHeader('Authorization', 'Bearer '.OUTBOUND_TOKEN)
        && ! str_contains($request->url(), OUTBOUND_TOKEN)
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '525511112222'
        && $request['type'] === 'text'
        && $request['text'] === ['body' => 'Con gusto le ayudo', 'preview_url' => false]
        && $request['biz_opaque_callback_data'] === $message->client_reference
        && ! isset($request['context']));

    expect($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('wamid.text.1')
        ->accepted_at->not->toBeNull()
        ->sent_at->toBeNull()
        ->delivered_at->toBeNull()
        ->delivery_attempts->toBe(1)
        ->and($this->connection->refresh()->last_outbound_at)->not->toBeNull();

    Event::assertDispatched(MessageStatusChanged::class, fn (MessageStatusChanged $event): bool => $event->status === 'sending' && $event->previousStatus === 'queued');
    Event::assertDispatched(MessageStatusChanged::class, fn (MessageStatusChanged $event): bool => $event->status === 'accepted' && $event->previousStatus === 'sending');
});

test('a reply to a customer message carries the WhatsApp context', function () {
    Http::fake([$this->messagesUrl => Http::response(acceptedByMeta())]);
    $quoted = Message::factory()->create(['conversation_id' => $this->conversation->id, 'whatsapp_message_id' => 'wamid.customer.question']);
    $message = WhatsAppFixtures::outbound($this->conversation, ['reply_to_message_id' => $quoted->id]);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => $request['context'] === ['message_id' => 'wamid.customer.question']);
});

test('a media reply is uploaded from the private disk once and sent by id', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/2026/10/receipt', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    Http::fake([
        'graph.facebook.com/v26.0/106540352242922/media' => Http::response(['id' => '1037543291543636']),
        $this->messagesUrl => Http::response(acceptedByMeta('wamid.image.1')),
    ]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image', 'body' => 'Su comprobante']);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/receipt', 'mime_type' => 'image/png', 'file_name' => 'comprobante.png', 'size_bytes' => 68])->save();

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/media')
        && $request->isMultipart()
        && $request->hasHeader('Authorization', 'Bearer '.OUTBOUND_TOKEN));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages')
        && $request['type'] === 'image'
        && $request['image'] === ['id' => '1037543291543636', 'caption' => 'Su comprobante']);

    expect($attachment->refresh()->whatsapp_media_id)->toBe('1037543291543636')
        ->and($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('a document keeps its file name and a missing file fails without reaching Meta', function () {
    Storage::fake('local');
    Http::fake();
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'document', 'body' => null]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/gone', 'mime_type' => 'application/pdf', 'file_name' => 'factura.pdf', 'size_bytes' => 10])->save();

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('provider_rejected')
        ->error_message->toContain('ya no existe')
        ->and($attachment->refresh()->status)->toBe(AttachmentStatus::Available);
    Http::assertNothingSent();
});

test('Meta refusals fail the message with an actionable Spanish explanation', function (array $error, int $status, string $code, string $explanation) {
    Http::fake([$this->messagesUrl => Http::response(['error' => $error], $status)]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toContain($explanation)
        ->error_message->not->toContain(OUTBOUND_TOKEN)
        ->and($this->conversation->events()->where('type', ConversationEventType::MessageFailed)->exists())->toBeTrue();
    Http::assertSentCount(1);
})->with([
    're-engagement window' => [['message' => 'Re-engagement message', 'code' => 131047], 400, 'meta_131047', 'solo puedes escribirle con una plantilla aprobada'],
    'test number not allowed' => [['message' => 'Recipient phone number not in allowed list', 'code' => 131030], 400, 'meta_131030', 'lista de números permitidos'],
    'invalid parameter' => [['message' => 'Invalid parameter', 'code' => 100], 400, 'provider_rejected', 'un identificador no existe'],
    'missing permission' => [['message' => '(#10) Application does not have permission for this action', 'code' => 10], 403, 'permission_denied', 'permisos necesarios'],
]);

test('a rejected token marks the connection credentials invalid and announces the health change', function () {
    Event::fake([ConnectionHealthChanged::class]);
    Http::fake([$this->messagesUrl => Http::response(['error' => ['message' => 'Error validating access token: Session has expired. '.OUTBOUND_TOKEN, 'type' => 'OAuthException', 'code' => 190]], 401)]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('credentials_invalid')
        ->error_message->not->toContain(OUTBOUND_TOKEN)
        ->and($this->connection->refresh())
        ->credentials_error->toContain('(código 190)')
        ->credentials_error->not->toContain(OUTBOUND_TOKEN)
        ->credentials_verified_at->toBeNull();

    Event::assertDispatched(ConnectionHealthChanged::class, fn (ConnectionHealthChanged $event): bool => $event->connectionId === $this->connection->id
        && $event->health === 'credentials_invalid');
});

test('errors proven not to have reached Meta go back to the queue with bounded attempts', function (Closure $failure, string $finalCode) {
    Http::fake([$this->messagesUrl => $failure()]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Queued)
        ->delivery_attempts->toBe(1)
        ->sending_started_at->toBeNull()
        ->and($message->metadata['delivery']['last_retryable_error'])->toBeString();

    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe($finalCode)
        ->delivery_attempts->toBe(2);
})->with([
    'DNS failure' => [fn () => Http::failedConnection(), 'provider_unreachable'],
    'connection refused' => [fn () => Http::failedConnection('cURL error 7: Failed to connect to graph.facebook.com port 443: Connection refused'), 'provider_unreachable'],
    'throttled (429)' => [fn () => Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 130429]], 429), 'rate_limited'],
    'pair rate limit' => [fn () => Http::response(['error' => ['message' => 'Spam rate limit hit', 'code' => 131056]], 400), 'rate_limited'],
    'explicit temporary error' => [fn () => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 131016]], 503), 'provider_unavailable'],
]);

test('an ambiguous outcome is never re-sent: it waits for evidence and is reconciled as unknown', function (Closure $failure) {
    Http::fake([$this->messagesUrl => $failure()]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);
    ($this->deliver)($message);

    expect($message->refresh())
        ->status->toBe(MessageStatus::Sending)
        ->whatsapp_message_id->toBeNull()
        ->and($message->metadata['delivery']['unconfirmed'])->toBeString();
    Http::assertSentCount(1);

    $this->travel(10)->minutes();
    $this->artisan('whatsapp:maintenance', ['task' => 'reconcile-deliveries'])->assertSuccessful();
    expect($message->refresh()->status)->toBe(MessageStatus::Sending);

    $this->travel(6)->minutes();
    $this->artisan('whatsapp:maintenance', ['task' => 'reconcile-deliveries'])->assertSuccessful();

    expect($message->refresh())
        ->status->toBe(MessageStatus::Failed)
        ->error_code->toBe('delivery_unknown')
        ->error_message->toContain('podría recibirlo dos veces')
        ->and($this->conversation->events()->where('type', ConversationEventType::MessageFailed)->exists())->toBeTrue();
    Http::assertSentCount(1);
})->with([
    'response timeout' => [fn () => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received')],
    'gateway error without Meta body' => [fn () => Http::response('<html>502 Bad Gateway</html>', 502)],
    'answer without message id' => [fn () => Http::response(['messaging_product' => 'whatsapp'])],
]);

test('two workers holding the same message send it once', function () {
    $message = WhatsAppFixtures::outbound($this->conversation);
    $transport = WhatsAppFixtures::transport(function (Message $sending) {
        // A second worker picks the same job while the first one is calling Meta.
        app()->call([new DeliverOutboundMessage($sending->id), 'handle']);

        return new SendResult('wamid.once');
    });

    ($this->deliver)($message);
    ($this->deliver)($message);

    expect($transport->sent)->toBe([$message->id])
        ->and($message->refresh())
        ->status->toBe(MessageStatus::Accepted)
        ->delivery_attempts->toBe(1);
});

test('without the integration enabled nothing is sent and the message fails explicitly', function () {
    config(['whatsapp.transport' => 'unavailable']);
    Http::fake();
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('transport_unavailable');
    Http::assertNothingSent();
});

test('during a controlled pilot only the listed recipients are reached', function (string $recipients, bool $sent) {
    config(['whatsapp.pilot.recipients' => array_values(array_filter(array_map(fn (string $number): string => (string) preg_replace('/\D+/', '', $number), explode(',', $recipients))))]);
    Http::fake([$this->messagesUrl => Http::response(acceptedByMeta('wamid.pilot.1'))]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    if ($sent) {
        expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
        Http::assertSentCount(1);
    } else {
        expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('transport_unavailable');
        Http::assertNothingSent();
    }
})->with([
    'recipient in the pilot' => ['+52 1 55 1111 2222, 5215599990000', true],
    'recipient outside the pilot' => ['5215599990000', false],
    'no pilot configured' => ['', true],
]);
