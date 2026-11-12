<?php

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionProvider;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppWebState;
use App\Jobs\DeliverOutboundMessage;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Storage\MediaDisk;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;
use Tests\Support\WhatsAppWebFixtures;

/*
| Sending through a phone linked by QR and the files of both directions
| (contract §4 and §6), with the Node service as a double. The delivery job
| is the real one: claims, order, failures and "unconfirmed" are shared with
| WhatsApp Cloud API.
*/

/** A 1x1 PNG. */
function wwebPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** An available outbound attachment already stored on the media disk. */
function wwebStoredAttachment(Message $message, string $path, string $name): MessageAttachment
{
    $attachment = (new MessageAttachment)->forceFill([
        'message_id' => $message->id, 'disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'file_name' => $name,
        'size_bytes' => strlen(wwebPng()), 'status' => AttachmentStatus::Available,
    ]);
    $attachment->save();

    return $attachment;
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    WhatsAppWebFixtures::configure();
    config(['whatsapp.transport' => 'meta', 'whatsapp.delivery.max_attempts' => 2, 'filesystems.media_disk' => 'local', 'services.meta.graph_version' => 'v26.0']);

    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $this->connection = Connection::factory()->whatsAppWeb()->create();
    $this->conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'connection_id' => $this->connection->id,
        // Days ago: a phone linked by QR has no 24 hour window.
        'last_inbound_at' => now()->subDays(5),
    ]);
    ContactIdentity::factory()->create(['contact_id' => $this->conversation->contact_id, 'external_id' => '5215511112222']);
    $this->messagesUrl = WhatsAppWebFixtures::SERVICE.'/v1/sessions/'.$this->connection->id.'/messages';
    $this->mediaUrl = WhatsAppWebFixtures::SERVICE.'/v1/sessions/'.$this->connection->id.'/media';
    $this->deliver = fn (Message $message) => app()->call([new DeliverOutboundMessage($message->id), 'handle']);
});

test('a text reply goes to the service with a stable idempotency key and becomes accepted', function () {
    Http::fake([$this->messagesUrl => Http::response(['provider_message_id' => '3EB0SENT0001', 'timestamp' => now()->getTimestamp()])]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['body' => 'Recibido, gracias']);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => $request->url() === $this->messagesUrl
        && $request->method() === 'POST'
        && $request['idempotency_key'] === 'cm-msg-'.$message->id
        && $request['to'] === '5215511112222'
        && $request['type'] === 'text'
        && $request['text'] === 'Recibido, gracias'
        && ! isset($request['quoted'])
        && WhatsAppWebFixtures::isSigned($request));

    // Nothing ever reaches Meta for a connection linked by QR.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'));

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($message->whatsapp_message_id)->toBe(WhatsAppWebTransport::storedId($this->connection->id, '3EB0SENT0001'))
        ->and($this->connection->refresh()->last_outbound_at)->not->toBeNull();
});

test('a reply quotes the WhatsApp Web message it answers', function () {
    Http::fake([$this->messagesUrl => Http::response(['provider_message_id' => '3EB0SENT0002', 'timestamp' => now()->getTimestamp()])]);
    $inbound = Message::factory()->create([
        'conversation_id' => $this->conversation->id,
        'direction' => 'inbound',
        'whatsapp_message_id' => WhatsAppWebTransport::storedId($this->connection->id, '3EB0QUOTED'),
        'body' => '¿Ya pasaron por el pago?',
    ]);
    $message = WhatsAppFixtures::outbound($this->conversation, ['reply_to_message_id' => $inbound->id]);

    ($this->deliver)($message);

    // The quoted text travels with the reply: the service keeps no messages.
    Http::assertSent(fn (Request $request): bool => $request['quoted'] === [
        'provider_message_id' => '3EB0QUOTED',
        'from_me' => false,
        'remote_jid' => '5215511112222@s.whatsapp.net',
        'text' => '¿Ya pasaron por el pago?',
    ]);
});

test('a person WhatsApp Web already saw is addressed by the JID of the chat', function (string $externalId, string $expected) {
    Http::fake([$this->messagesUrl => Http::response(['provider_message_id' => '3EB0SENT0003', 'timestamp' => now()->getTimestamp()])]);
    ContactIdentity::query()->where('contact_id', $this->conversation->contact_id)->delete();
    ContactIdentity::factory()->create([
        'contact_id' => $this->conversation->contact_id,
        'external_id' => $externalId,
        'provider' => ConnectionProvider::WhatsappWeb,
    ]);

    ($this->deliver)(WhatsAppFixtures::outbound($this->conversation));

    Http::assertSent(fn (Request $request): bool => $request['to'] === $expected);
})->with([
    'a phone' => ['5215511112222', '5215511112222@s.whatsapp.net'],
    'a privacy id' => ['lid:178234567890123', '178234567890123@lid'],
]);

test('a session that is not connected fails the message with a clear reason and sends nothing', function () {
    Http::fake();
    $this->connection->forceFill(['web_session_state' => WhatsAppWebState::LoggedOut, 'web_session_linked_at' => null])->save();
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    Http::assertNothingSent();
    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('transport_unavailable')
        ->and($message->error_message)->toContain('requiere una nueva vinculación');
});

test('when the service says the session is not connected the stored state is corrected and the message fails', function () {
    Http::fake([
        $this->messagesUrl => Http::response(['error' => 'not_connected', 'state' => 'reconnecting'], 409),
        WhatsAppWebFixtures::SERVICE.'/v1/sessions/'.$this->connection->id => Http::response(WhatsAppWebFixtures::session($this->connection, 'reconnecting', ['reason' => 'network'])),
    ]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('transport_unavailable')
        ->and($message->error_message)->toContain('reconectando')
        ->and($this->connection->refresh()->webSessionState())->toBe(WhatsAppWebState::Reconnecting);
});

test('an answer that never arrives leaves the message unconfirmed and it is never re-sent automatically', function (Closure $answer) {
    Http::fake([$this->messagesUrl => $answer]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Sending)
        ->and($message->whatsapp_message_id)->toBeNull()
        ->and($message->metadata['delivery']['unconfirmed'])->toContain('No se reenviará');

    // The delivery job never claims it again by itself.
    ($this->deliver)($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Sending);
})->with([
    'the service timed out waiting for WhatsApp (504)' => [fn () => Http::response(['error' => 'send_timeout'], 504)],
    'the service did not answer in time' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received')],
]);

test('a service that refuses the connection is retried and then fails explicitly, never as sent', function () {
    Http::fake([$this->messagesUrl => fn () => throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 3410: Connection refused', 0, new ConnectException(
        'Connection refused', new GuzzleHttp\Psr7\Request('POST', $this->messagesUrl), null, ['errno' => 7],
    ))]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    // Nothing left this server: the message is put back in the queue.
    expect($message->refresh()->status)->toBe(MessageStatus::Queued);

    $this->travel(10)->minutes();
    ($this->deliver)($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('provider_unreachable')
        ->and($message->error_message)->toContain('no responde');
});

test('a recipient or a file the service refuses is a definitive failure', function () {
    Http::fake([$this->messagesUrl => Http::response(['error' => 'invalid_recipient'], 422)]);
    $message = WhatsAppFixtures::outbound($this->conversation);

    ($this->deliver)($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->error_code)->toBe('provider_rejected')
        ->and($message->error_message)->toContain('no reconoce el número');

    Http::assertSentCount(1);
});

test('a file is offered to the service through a signed internal URL that works once and only for that kind of attachment', function () {
    Http::fake([$this->messagesUrl => Http::response(['provider_message_id' => '3EB0IMAGE', 'timestamp' => now()->getTimestamp()])]);
    Storage::disk('local')->put('media/2026/11/foto.png', wwebPng());
    $message = WhatsAppFixtures::outbound($this->conversation, ['type' => 'image', 'body' => 'Comprobante']);
    $attachment = wwebStoredAttachment($message, 'media/2026/11/foto.png', 'foto.png');

    ($this->deliver)($message);

    $url = null;
    Http::assertSent(function (Request $request) use (&$url): bool {
        $url = $request['media']['url'] ?? null;

        return $request['type'] === 'image' && $request['text'] === 'Comprobante'
            && $request['media']['mime'] === 'image/png' && $request['media']['file_name'] === 'foto.png' && $request['media']['voice'] === false;
    });

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($url)->toContain('/internal/whatsapp-web/media/'.$attachment->id.'?')->toContain('sig=')->toContain('nonce=')->toContain('ts=')
        // Never a path of the disk.
        ->and($url)->not->toContain('media/2026');

    $relative = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($relative)->assertOk()->assertHeader('Content-Type', 'image/png');
    // The URL works once.
    $this->get($relative)->assertUnauthorized();
    // And never without its signature.
    $this->get('/internal/whatsapp-web/media/'.$attachment->id)->assertUnauthorized();
});

test('the internal file URL never serves a file of another modality', function () {
    $cloud = Conversation::factory()->create(['connection_id' => Connection::factory()->verified()->create()->id]);
    Storage::disk('local')->put('media/2026/11/otro.png', wwebPng());
    $message = WhatsAppFixtures::outbound($cloud, ['type' => 'image']);
    $attachment = wwebStoredAttachment($message, 'media/2026/11/otro.png', 'otro.png');

    $url = app(WhatsAppWebTransport::class)->mediaUrl($attachment);

    $this->get((string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))->assertNotFound();
});

test('a WhatsApp Cloud API connection keeps sending through Meta exactly as before', function () {
    $cloud = Connection::factory()->verified()->create(['phone_number_id' => '106540352242922', 'access_token' => 'EAAGcloudToken00000000000000000000000000000000000']);
    $conversation = Conversation::factory()->create(['connection_id' => $cloud->id, 'last_inbound_at' => now()->subHour(), 'assigned_user_id' => $this->conversation->assigned_user_id, 'department_id' => $this->conversation->department_id, 'status' => $this->conversation->status]);
    ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'external_id' => '5215533334444']);
    Http::fake(['graph.facebook.com/v26.0/106540352242922/messages' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.cloud.1']]])]);
    $message = WhatsAppFixtures::outbound($conversation);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com/v26.0/106540352242922/messages') && $request['to'] === '525533334444');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), WhatsAppWebFixtures::SERVICE));

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($message->whatsapp_message_id)->toBe('wamid.cloud.1');
});

test('an inbound file is downloaded from the service, validated and stored on the private media disk', function () {
    $descriptor = ['mediaKey' => 'c2VjcmV0', 'directPath' => '/v/file.enc', 'type' => 'image'];
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'type' => 'image']);
    $attachment = (new MessageAttachment)->forceFill([
        'message_id' => $message->id, 'disk' => 'local', 'path' => null, 'mime_type' => 'image/jpeg', 'status' => AttachmentStatus::Pending, 'provider_reference' => $descriptor,
    ]);
    $attachment->save();
    Http::fake([$this->mediaUrl => Http::response(wwebPng(), 200, ['Content-Type' => 'image/png'])]);

    app()->call([new DownloadWhatsAppWebMedia($attachment->id), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === $this->mediaUrl
        && $request['descriptor'] === $descriptor
        && WhatsAppWebFixtures::isSigned($request));

    $attachment->refresh();

    expect($attachment->status)->toBe(AttachmentStatus::Available)
        // The real type wins over the declared one.
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->path)->toStartWith('media/')
        ->and($attachment->provider_reference)->toBeNull();

    Storage::disk('local')->assertExists($attachment->path);
});

test('a file of a forbidden type is marked unavailable and nothing is stored', function () {
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'type' => 'image']);
    $attachment = (new MessageAttachment)->forceFill([
        'message_id' => $message->id, 'disk' => 'local', 'path' => null, 'mime_type' => 'image/jpeg', 'status' => AttachmentStatus::Pending, 'provider_reference' => ['mediaKey' => 'x'],
    ]);
    $attachment->save();
    Http::fake([$this->mediaUrl => Http::response('<?php echo "not an image";', 200, ['Content-Type' => 'image/jpeg'])]);

    app()->call([new DownloadWhatsAppWebMedia($attachment->id), 'handle']);

    expect($attachment->refresh()->status)->toBe(AttachmentStatus::Unavailable)
        ->and($attachment->error_code)->toBe('type_not_allowed')
        ->and($attachment->path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('with the required media disk unavailable the file stays pending and is never written anywhere else', function () {
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'type' => 'image']);
    $attachment = (new MessageAttachment)->forceFill([
        'message_id' => $message->id, 'disk' => 'nas', 'path' => null, 'mime_type' => 'image/jpeg', 'status' => AttachmentStatus::Pending, 'provider_reference' => ['mediaKey' => 'x'],
    ]);
    $attachment->save();
    Http::fake();

    // The NAS share is not mounted.
    $disk = Mockery::mock(MediaDisk::class);
    $disk->shouldReceive('ready')->andReturn(false);
    app()->instance(MediaDisk::class, $disk);

    $job = new DownloadWhatsAppWebMedia($attachment->id);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    Http::assertNothingSent();

    expect($attachment->refresh()->status)->toBe(AttachmentStatus::Pending)
        ->and($attachment->path)->toBeNull()
        ->and($attachment->provider_reference)->not->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('a download the session cannot serve right now is retried later', function () {
    $message = Message::factory()->create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'type' => 'image']);
    $attachment = (new MessageAttachment)->forceFill([
        'message_id' => $message->id, 'disk' => 'local', 'path' => null, 'mime_type' => 'image/jpeg', 'status' => AttachmentStatus::Pending, 'provider_reference' => ['mediaKey' => 'x'],
    ]);
    $attachment->save();
    Http::fake([$this->mediaUrl => Http::response(['error' => 'not_connected'], 409)]);

    $job = new DownloadWhatsAppWebMedia($attachment->id);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    expect($attachment->refresh()->status)->toBe(AttachmentStatus::Pending);
});
