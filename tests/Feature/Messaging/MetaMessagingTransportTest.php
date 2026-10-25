<?php

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\MessageStatus;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;

/*
| Outbound replies through Facebook Messenger and Instagram (Send API).
| Every Graph call is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.meta.app_secret' => 'facebook-app-secret-value',
        'services.meta.instagram_app_secret' => 'instagram-app-secret-value',
        'services.meta.graph_version' => 'v26.0',
        'whatsapp.delivery.max_attempts' => 2,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $this->department = Department::factory()->create();
    $this->agent = User::factory()->agent()->inDepartments($this->department)->create();
    $this->deliver = fn (Message $message) => app()->call([new DeliverOutboundMessage($message->id), 'handle']);
});

function metaConversation(mixed $test, Connection $connection, string $customerId, ?CarbonImmutable $lastInbound = null): Conversation
{
    $conversation = Conversation::factory()->inDepartment($test->department)->assignedTo($test->agent)->create([
        'connection_id' => $connection->id,
        'last_inbound_at' => $lastInbound ?? now()->subHour(),
    ]);
    ContactIdentity::factory()->create([
        'contact_id' => $conversation->contact_id,
        'channel' => $connection->channel,
        'provider' => $connection->channel === ConnectionChannel::Instagram ? ConnectionProvider::MetaInstagram : ConnectionProvider::MetaMessenger,
        'external_id' => $customerId,
    ]);

    return $conversation;
}

test('a Messenger reply is sent to the PSID as a RESPONSE with the page token and appsecret_proof', function () {
    $page = Connection::factory()->messenger()->create();
    $page->forceFill(['external_account_id' => '1122334455', 'access_token' => 'PAGE-TOKEN-1'])->save();
    Http::fake(['graph.facebook.com/v26.0/1122334455/messages*' => Http::response(['recipient_id' => '2001', 'message_id' => 'm_sent_1'])]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), ['body' => 'Con gusto le ayudo']);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/1122334455/messages?appsecret_proof='.hash_hmac('sha256', 'PAGE-TOKEN-1', 'facebook-app-secret-value'))
        && $request->hasHeader('Authorization', 'Bearer PAGE-TOKEN-1')
        && $request['recipient'] === ['id' => '2001']
        && $request['messaging_type'] === 'RESPONSE'
        && $request['message'] === ['text' => 'Con gusto le ayudo']);

    expect($message->refresh())->status->toBe(MessageStatus::Accepted)->whatsapp_message_id->toBe('m_sent_1');
});

test('an Instagram reply uses graph.instagram.com with the Instagram app secret proof', function () {
    $account = Connection::factory()->instagram()->create();
    $account->forceFill(['external_account_id' => '17841400000000001', 'access_token' => 'IG-LONG'])->save();
    Http::fake(['graph.instagram.com/v26.0/me/messages*' => Http::response(['recipient_id' => '3001', 'message_id' => 'ig_sent_1'])]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $account, '3001'), ['body' => 'Hola']);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', 'IG-LONG', 'instagram-app-secret-value'))
        && $request['recipient'] === ['id' => '3001']);
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('a reply outside the 24 hour window is blocked before reaching Meta', function () {
    $page = Connection::factory()->messenger()->create();
    Http::fake();
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001', now()->subHours(25)));

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('customer_window_closed');
    Http::assertNothingSent();
});

test('an image is sent by a signed URL that only serves that file', function () {
    Storage::fake('local');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Storage::disk('local')->put('media/2026/10/foto', $png);
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_img_1'])]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), ['type' => 'image', 'body' => null]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/foto', 'mime_type' => 'image/png', 'file_name' => 'foto.png', 'size_bytes' => strlen($png), 'status' => AttachmentStatus::Available])->save();

    ($this->deliver)($message);

    $url = null;
    Http::assertSent(function (Request $request) use (&$url): bool {
        $url = $request['message']['attachment']['payload']['url'] ?? null;

        return ($request['message']['attachment']['type'] ?? null) === 'image' && is_string($url);
    });

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get(route('meta.media', $attachment))->assertForbidden();
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('a page token Meta revoked fails the message with a Spanish explanation', function () {
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 401)]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'));

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)
        ->and($message->error_message)->toContain('Vuelve a autorizar')
        ->not->toContain('Error validating');
});

test('a ZIP file travels on Messenger as a text with its secure download link', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/2026/10/archivo', 'PK');
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_zip_1'])]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), [
        'type' => 'document',
        'body' => null,
        'metadata' => ['download_link' => ['expires_at' => now()->addDays(7)->toIso8601String()]],
    ]);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/archivo', 'mime_type' => 'application/zip', 'file_name' => 'catalogo.zip', 'size_bytes' => 2, 'status' => AttachmentStatus::Available, 'delivery_method' => AttachmentDeliveryMethod::Link])->save();

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => ! isset($request['message']['attachment'])
        && str_contains((string) ($request['message']['text'] ?? ''), 'catalogo.zip')
        && str_contains((string) ($request['message']['text'] ?? ''), '/descargas/'.$attachment->id));
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
});

/**
 * A stored outbound file of the conversation's reply.
 */
function metaOutboundFile(Message $message, string $contents, string $mime, string $name, AttachmentDeliveryMethod $delivery = AttachmentDeliveryMethod::Native): MessageAttachment
{
    Storage::disk('local')->put('media/2026/10/'.$message->id, $contents);
    $attachment = new MessageAttachment;
    $attachment->forceFill(['message_id' => $message->id, 'disk' => 'local', 'path' => 'media/2026/10/'.$message->id, 'mime_type' => $mime, 'file_name' => $name, 'size_bytes' => strlen($contents), 'status' => AttachmentStatus::Available, 'delivery_method' => $delivery])->save();

    return $attachment;
}

test('the reply goes to the PSID that wrote in this conversation, not to another page identity of the contact', function () {
    $page = Connection::factory()->messenger()->create();
    $page->forceFill(['external_account_id' => '1122334455'])->save();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_sent_2'])]);
    $conversation = metaConversation($this, $page, '2001');
    // The same person also wrote to another page: a newer identity with another PSID.
    ContactIdentity::factory()->create(['contact_id' => $conversation->contact_id, 'channel' => ConnectionChannel::Messenger, 'provider' => ConnectionProvider::MetaMessenger, 'external_id' => '9009', 'last_seen_at' => now()]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'text', 'status' => 'received', 'metadata' => ['sender_external_id' => '2001']]);
    $message = WhatsAppFixtures::outbound($conversation);

    ($this->deliver)($message);

    Http::assertSent(fn (Request $request): bool => $request['recipient'] === ['id' => '2001']);
    Http::assertNotSent(fn (Request $request): bool => $request['recipient'] === ['id' => '9009']);
});

test('with the Human Agent tag a reply after 24 hours goes tagged and the window closes after 7 days', function () {
    config(['services.meta.human_agent_tag' => true]);
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['message_id' => 'm_tagged'])->push(['message_id' => 'm_recent'])]);
    $tagged = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001', now()->subDays(3)));
    $recent = WhatsAppFixtures::outbound(metaConversation($this, $page, '2002', now()->subHour()));
    $expired = WhatsAppFixtures::outbound(metaConversation($this, $page, '2003', now()->subDays(8)));

    ($this->deliver)($tagged);
    ($this->deliver)($recent);
    ($this->deliver)($expired);

    Http::assertSent(fn (Request $request): bool => $request['recipient'] === ['id' => '2001'] && $request['messaging_type'] === 'MESSAGE_TAG' && $request['tag'] === 'HUMAN_AGENT');
    Http::assertSent(fn (Request $request): bool => $request['recipient'] === ['id' => '2002'] && $request['messaging_type'] === 'RESPONSE' && ! isset($request['tag']));
    Http::assertNotSent(fn (Request $request): bool => $request['recipient'] === ['id' => '2003']);
    expect($tagged->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($expired->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('customer_window_closed');
});

test('a text Instagram would refuse stops the message before its file is sent', function () {
    Storage::fake('local');
    $account = Connection::factory()->instagram()->create();
    Http::fake();
    $message = WhatsAppFixtures::outbound(metaConversation($this, $account, '3001'), ['type' => 'document', 'body' => str_repeat('á', 600)]);
    metaOutboundFile($message, '%PDF-1.4', 'application/pdf', 'cotizacion.pdf');

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)
        ->and($message->error_message)->toContain('1000 bytes');
    Http::assertNothingSent();
});

test('when the text after a file fails the message keeps the file acceptance with a visible warning', function () {
    Storage::fake('local');
    $page = Connection::factory()->messenger()->create();
    Http::fakeSequence('graph.facebook.com/*')
        ->push(['message_id' => 'm_file'])
        ->push(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), ['type' => 'document', 'body' => 'Aquí está su cotización']);
    metaOutboundFile($message, '%PDF-1.4', 'application/pdf', 'cotizacion.pdf');

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Accepted)
        ->whatsapp_message_id->toBe('m_file')
        ->and($message->metadata['delivery_warning'])->toContain('el texto que lo acompañaba no llegó');
    Http::assertSentCount(2);
});

test('expired or revoked download links are never sent again on Messenger', function (array $link, string $error) {
    Storage::fake('local');
    $page = Connection::factory()->messenger()->create();
    Http::fake();
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), ['type' => 'document', 'body' => null, 'metadata' => ['download_link' => $link]]);
    metaOutboundFile($message, 'PK', 'application/zip', 'catalogo.zip', AttachmentDeliveryMethod::Link);

    ($this->deliver)($message);

    expect($message->refresh())->status->toBe(MessageStatus::Failed)
        ->and($message->error_message)->toContain($error);
    Http::assertNothingSent();
})->with([
    'expired' => [fn () => ['expires_at' => now()->subMinute()->toIso8601String()], 'ya venció'],
    'without expiry' => [fn () => [], 'ya venció'],
    'revoked' => [fn () => ['expires_at' => now()->addDays(3)->toIso8601String(), 'revoked_at' => now()->toIso8601String()], 'fue revocado'],
]);

test('an Instagram download link text fits 1000 bytes, and a caption that cannot fit is refused clearly', function () {
    Storage::fake('local');
    $account = Connection::factory()->instagram()->create();
    Http::fake(['graph.instagram.com/*' => Http::response(['message_id' => 'ig_zip'])]);
    $link = ['download_link' => ['expires_at' => now()->addDays(7)->toIso8601String()]];
    $fits = WhatsAppFixtures::outbound(metaConversation($this, $account, '3001'), ['type' => 'document', 'body' => str_repeat('Detalle del pedido. ', 30), 'metadata' => $link]);
    metaOutboundFile($fits, 'PK', 'application/zip', str_repeat('reporte-mensual-de-ventas-', 8).'.zip', AttachmentDeliveryMethod::Link);
    $tooLong = WhatsAppFixtures::outbound(metaConversation($this, $account, '3002'), ['type' => 'document', 'body' => str_repeat('ñ', 480), 'metadata' => $link]);
    metaOutboundFile($tooLong, 'PK', 'application/zip', 'datos.zip', AttachmentDeliveryMethod::Link);

    ($this->deliver)($fits);
    ($this->deliver)($tooLong);

    Http::assertSent(fn (Request $request): bool => $request['recipient'] === ['id' => '3001']
        && strlen((string) $request['message']['text']) <= 1000
        && str_contains((string) $request['message']['text'], '.zip')
        && str_contains((string) $request['message']['text'], '/descargas/'));
    expect($fits->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($tooLong->refresh())->status->toBe(MessageStatus::Failed)
        ->and($tooLong->error_message)->toContain('Envía el texto en un mensaje aparte');
});

test('files fetched by Meta carry their name with the extension of their real type', function () {
    Storage::fake('local');
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_doc'])]);
    $message = WhatsAppFixtures::outbound(metaConversation($this, $page, '2001'), ['type' => 'document', 'body' => null]);
    $attachment = metaOutboundFile($message, '%PDF-1.4', 'application/pdf', 'contrato firmado');

    ($this->deliver)($message);

    $url = null;
    Http::assertSent(function (Request $request) use (&$url): bool {
        $url = $request['message']['attachment']['payload']['url'] ?? null;

        return ($request['message']['attachment']['type'] ?? null) === 'file';
    });

    $disposition = (string) $this->get($url)->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toContain('attachment')->toContain('filename="contrato firmado.pdf"')
        ->and($attachment->refresh()->file_name)->toBe('contrato firmado');
});
