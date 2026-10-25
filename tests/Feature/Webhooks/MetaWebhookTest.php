<?php

use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\WhatsAppFixtures;

/*
| App-level Meta webhook for Facebook Messenger and Instagram: verification,
| signature, per-account idempotent storage and the shared inbound/status
| pipeline. Every Graph call is faked.
*/

const MESSENGER_SECRET = 'facebook-app-secret-value';
const INSTAGRAM_SECRET = 'instagram-app-secret-value';

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.meta.app_id' => '1234567890',
        'services.meta.app_secret' => MESSENGER_SECRET,
        'services.meta.instagram_app_secret' => INSTAGRAM_SECRET,
        'services.meta.webhook_verify_token' => 'verify-token-1234567890',
        'services.meta.graph_version' => 'v26.0',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
    $this->page = Connection::factory()->messenger()->create();
    $this->page->forceFill(['external_account_id' => '1122334455'])->save();
});

/**
 * @param  array<string, mixed>  $payload
 */
function postMeta(mixed $test, array $payload, string $secret = MESSENGER_SECRET, ?string $signature = null): TestResponse
{
    $body = (string) json_encode($payload);

    return $test->call('POST', route('webhooks.meta.receive'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, $secret),
    ], content: $body);
}

/**
 * @param  list<array<string, mixed>>  $messaging
 * @return array<string, mixed>
 */
function metaBody(string $object, string $accountId, array $messaging): array
{
    return ['object' => $object, 'entry' => [['id' => $accountId, 'time' => now()->getTimestampMs(), 'messaging' => $messaging]]];
}

/**
 * @param  array<string, mixed>  $message
 * @return array<string, mixed>
 */
function customerMessage(string $from, string $to, array $message, ?CarbonImmutable $at = null): array
{
    return ['sender' => ['id' => $from], 'recipient' => ['id' => $to], 'timestamp' => ($at ?? now())->getTimestampMs(), 'message' => $message];
}

function fakeNoProfile(): void
{
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request']], 400)]);
}

test('the verification challenge is answered only with the configured verify token', function () {
    $url = route('webhooks.meta.verify');

    $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=987')->assertForbidden();
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-token-1234567890&hub.challenge=987')
        ->assertOk()->assertContent('987');

    config(['services.meta.webhook_verify_token' => null]);
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=&hub.challenge=987')->assertForbidden();
});

test('bodies with a bad signature are refused and nothing is stored', function () {
    $payload = metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_1', 'text' => 'Hola'])]);

    postMeta($this, $payload, signature: 'sha256=deadbeef')->assertUnauthorized();
    postMeta($this, $payload, secret: 'another-secret')->assertUnauthorized();

    expect(WebhookEvent::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0);
});

test('a Messenger text creates a provisional contact, its PSID identity and a conversation through the shared pipeline', function () {
    Http::fake(['graph.facebook.com/v26.0/2001*' => Http::response(['first_name' => 'Ana', 'last_name' => 'López', 'id' => '2001'])]);

    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_abc', 'text' => 'Hola, ¿tienen envío?'])]))->assertOk();

    $message = Message::query()->sole();
    $conversation = Conversation::query()->with('contact')->sole();

    expect($message)
        ->direction->toBe(MessageDirection::Inbound)
        ->whatsapp_message_id->toBe('m_abc')
        ->body->toBe('Hola, ¿tienen envío?')
        ->and($conversation->connection_id)->toBe($this->page->id)
        ->and($conversation->last_inbound_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($conversation->contact->name)->toBe('Ana López')
        ->and($conversation->contact->isSaved())->toBeFalse()
        ->and(ContactIdentity::query()->sole())->channel->toBe(ConnectionChannel::Messenger)->external_id->toBe('2001');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2001')
        && ($request->data()['appsecret_proof'] ?? null) === hash_hmac('sha256', (string) $this->page->access_token, MESSENGER_SECRET));
});

test('without profile access the contact keeps the channel fallback name, never an invented one', function () {
    fakeNoProfile();

    postMeta($this, metaBody('page', '1122334455', [customerMessage('20019988776655', '1122334455', ['mid' => 'm_x', 'text' => 'Hola'])]))->assertOk();

    expect(Conversation::query()->with('contact')->sole()->contact->name)->toBe('Usuario de Messenger 776655');
});

test('the same delivery twice stores one message and one webhook event', function () {
    fakeNoProfile();
    $payload = metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_dup', 'text' => 'Hola'])]);

    postMeta($this, $payload)->assertOk();
    postMeta($this, $payload)->assertOk();

    // Same message in a differently timed body (a Meta retry).
    $payload['entry'][0]['time']++;
    postMeta($this, $payload)->assertOk();

    expect(Message::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->where('connection_id', $this->page->id)->count())->toBe(2);
});

test('entries of unknown or disconnected accounts are acknowledged and ignored', function () {
    $this->page->forceFill(['access_token' => null])->save();

    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_1', 'text' => 'Hola'])]))->assertOk();
    postMeta($this, metaBody('page', '999', [customerMessage('2001', '999', ['mid' => 'm_2', 'text' => 'Hola'])]))->assertOk();

    expect(WebhookEvent::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0);
});

test('an Instagram message signed with the Instagram app secret enters the same pipeline', function () {
    $account = Connection::factory()->instagram()->create();
    $account->forceFill(['external_account_id' => '17841400000000001'])->save();
    Http::fake(['graph.instagram.com/v26.0/*' => Http::response(['username' => 'cliente.mx'])]);

    postMeta($this, metaBody('instagram', '17841400000000001', [customerMessage('3001', '17841400000000001', ['mid' => str_repeat('aWdfZAG1f', 20), 'text' => 'Precio?'])]), INSTAGRAM_SECRET)
        ->assertOk();

    $message = Message::query()->sole();
    expect($message->whatsapp_message_id)->toBe('sha256:'.hash('sha256', str_repeat('aWdfZAG1f', 20)))
        ->and($message->conversation->connection_id)->toBe($account->id)
        ->and($message->conversation->contact->name)->toBe('@cliente.mx')
        ->and(ContactIdentity::query()->sole()->channel)->toBe(ConnectionChannel::Instagram);
});

test('a postback becomes a text message with the button title and attachments are downloaded from Meta CDN', function () {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake([
        'scontent.xx.fbcdn.net/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 100]], 400),
    ]);

    postMeta($this, metaBody('page', '1122334455', [
        ['sender' => ['id' => '2001'], 'recipient' => ['id' => '1122334455'], 'timestamp' => now()->getTimestampMs(), 'postback' => ['title' => 'Ver catálogo', 'payload' => 'CATALOG', 'mid' => 'm_pb']],
        customerMessage('2001', '1122334455', ['mid' => 'm_img', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/foto.png']]]]),
    ]))->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'm_pb')->sole())->type->toBe(MessageType::Text)->body->toBe('Ver catálogo');
    $image = Message::query()->where('whatsapp_message_id', 'm_img')->sole();
    expect($image->type)->toBe(MessageType::Image)
        ->and($image->attachments->sole())->status->toBe(AttachmentStatus::Available)->mime_type->toBe('image/png');
});

test('a future timestamp is capped at now so it never extends the customer window', function () {
    fakeNoProfile();

    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_future', 'text' => 'Hola'], now()->addDay())]))->assertOk();

    expect(Conversation::query()->sole()->last_inbound_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('Messenger delivery and read watermarks advance earlier outbound messages and never move a status back', function () {
    fakeNoProfile();
    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_in', 'text' => 'Hola'])]));
    $conversation = Conversation::query()->sole();

    $early = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'm_out_1', 'sending_started_at' => now()->subMinutes(5), 'accepted_at' => now()->subMinutes(5)]);
    $read = WhatsAppFixtures::outbound($conversation, ['status' => 'read', 'whatsapp_message_id' => 'm_out_2', 'sending_started_at' => now()->subMinutes(4), 'accepted_at' => now()->subMinutes(4), 'read_at' => now()->subMinutes(3)]);
    $unknown = WhatsAppFixtures::outbound($conversation, ['status' => 'failed', 'error_code' => 'delivery_unknown', 'whatsapp_message_id' => null, 'sending_started_at' => now()->subMinutes(4)]);
    $late = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'm_out_3', 'sending_started_at' => now()->addMinute(), 'accepted_at' => now()->addMinute()]);

    $watermark = now()->subMinutes(2)->getTimestampMs();
    postMeta($this, metaBody('page', '1122334455', [
        ['sender' => ['id' => '2001'], 'recipient' => ['id' => '1122334455'], 'timestamp' => $watermark, 'delivery' => ['watermark' => $watermark]],
    ]))->assertOk();

    expect($early->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($read->refresh()->status)->toBe(MessageStatus::Read)
        ->and($unknown->refresh())->status->toBe(MessageStatus::Failed)->error_code->toBe('delivery_unknown')
        ->and($late->refresh()->status)->toBe(MessageStatus::Accepted);

    postMeta($this, metaBody('page', '1122334455', [
        ['sender' => ['id' => '2001'], 'recipient' => ['id' => '1122334455'], 'timestamp' => $watermark + 1, 'read' => ['watermark' => $watermark]],
    ]))->assertOk();

    expect($early->refresh()->status)->toBe(MessageStatus::Read)
        ->and($late->refresh()->status)->toBe(MessageStatus::Accepted);
});

test('Instagram seen events mark the message they name as read', function () {
    $account = Connection::factory()->instagram()->create();
    $account->forceFill(['external_account_id' => '17841400000000001'])->save();
    Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['code' => 100]], 400)]);
    postMeta($this, metaBody('instagram', '17841400000000001', [customerMessage('3001', '17841400000000001', ['mid' => 'ig_in', 'text' => 'Hola'])]), INSTAGRAM_SECRET);
    $outbound = WhatsAppFixtures::outbound(Conversation::query()->sole(), ['status' => 'accepted', 'whatsapp_message_id' => 'ig_out_1', 'accepted_at' => now()]);

    postMeta($this, metaBody('instagram', '17841400000000001', [
        ['sender' => ['id' => '3001'], 'recipient' => ['id' => '17841400000000001'], 'timestamp' => now()->getTimestampMs(), 'read' => ['mid' => 'ig_out_1']],
    ]), INSTAGRAM_SECRET)->assertOk();

    expect($outbound->refresh()->status)->toBe(MessageStatus::Read);
});

test('the echo of a message sent from Chat Manager is not stored again', function () {
    fakeNoProfile();
    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_in', 'text' => 'Hola'])]));
    WhatsAppFixtures::outbound(Conversation::query()->sole(), ['status' => 'accepted', 'whatsapp_message_id' => 'm_ours', 'accepted_at' => now()]);

    postMeta($this, metaBody('page', '1122334455', [
        customerMessage('1122334455', '2001', ['mid' => 'm_ours', 'text' => 'Hola, le escribe el equipo de soporte', 'is_echo' => true]),
        customerMessage('1122334455', '2001', ['mid' => 'm_from_inbox', 'text' => 'Respuesta desde Meta Business Suite', 'is_echo' => true]),
    ]))->assertOk();

    expect(Message::query()->count())->toBe(2)
        ->and(WebhookEvent::query()->latest('id')->first()->summary)->toMatchArray(['echoes' => 1, 'ignored' => 1, 'messages' => 0]);
});

test('WhatsApp message timestamps in the future are capped at now as well', function () {
    $whatsapp = Connection::factory()->verified()->create();

    $receipt = receiveWhatsapp($whatsapp, at: now()->addHours(3));

    expect($receipt->conversation->last_inbound_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('each object must be signed with its own channel secret', function () {
    $account = Connection::factory()->instagram()->create();
    $account->forceFill(['external_account_id' => '17841400000000001'])->save();

    postMeta($this, metaBody('instagram', '17841400000000001', [customerMessage('3001', '17841400000000001', ['mid' => 'ig_1', 'text' => 'Hola'])]), MESSENGER_SECRET)
        ->assertUnauthorized();
    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_1', 'text' => 'Hola'])]), INSTAGRAM_SECRET)
        ->assertUnauthorized();

    expect(WebhookEvent::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0);
});

test('invalid signatures are throttled per address without slowing down signed deliveries', function () {
    fakeNoProfile();
    config(['services.meta.webhook_invalid_signatures_per_minute' => 2]);
    $payload = metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_ok', 'text' => 'Hola'])]);

    // Signed deliveries never count against the invalid-signature budget.
    foreach (range(1, 3) as $attempt) {
        postMeta($this, $payload)->assertOk();
    }

    postMeta($this, $payload, signature: 'sha256=deadbeef')->assertUnauthorized();
    postMeta($this, $payload, signature: 'sha256=deadbeef')->assertUnauthorized();
    postMeta($this, $payload, signature: 'sha256=deadbeef')->assertTooManyRequests();

    $body = (string) json_encode($payload);
    $this->call('POST', route('webhooks.meta.receive'), server: [
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, MESSENGER_SECRET),
    ], content: $body)->assertOk();
});

test('the webhook routes use their own per-address limiters', function () {
    expect(app('router')->getRoutes()->getByName('webhooks.meta.receive')->gatherMiddleware())->toContain('throttle:meta-webhooks')
        ->and(app('router')->getRoutes()->getByName('webhooks.meta.verify')->gatherMiddleware())->toContain('throttle:meta-webhook-verify');

    foreach (range(1, 30) as $attempt) {
        $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1');
    }

    $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=verify-token-1234567890&hub.challenge=1')->assertTooManyRequests();
    postMeta($this, metaBody('page', '999', []))->assertOk();
});

test('Messenger stickers and GIFs are kept as images, downloaded from the CDN without the page token', function () {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    Http::fake([
        'scontent.xx.fbcdn.net/*' => Http::response($gif, 200, ['Content-Type' => 'image/gif']),
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 100]], 400),
    ]);

    postMeta($this, metaBody('page', '1122334455', [
        customerMessage('2001', '1122334455', ['mid' => 'm_like', 'sticker_id' => 369239263222822, 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/like.gif', 'sticker_id' => 369239263222822]]]]),
    ]))->assertOk();

    $message = Message::query()->where('whatsapp_message_id', 'm_like')->sole();
    expect($message->type)->toBe(MessageType::Image)
        ->and($message->attachments->sole())->status->toBe(AttachmentStatus::Available)->mime_type->toBe('image/gif');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fbcdn.net') && ! $request->hasHeader('Authorization'));
});

test('Instagram audio above the WhatsApp limit but within 25 MB is kept', function () {
    Storage::fake('local');
    config(['filesystems.media_disk' => 'local']);
    $account = Connection::factory()->instagram()->create();
    $account->forceFill(['external_account_id' => '17841400000000001'])->save();
    $wav = 'RIFF'.pack('V', 17 * 1024 * 1024).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 17 * 1024 * 1024).str_repeat("\0", 17 * 1024 * 1024);
    Http::fake([
        'lookaside.fbsbx.com/*' => Http::response($wav, 200, ['Content-Type' => 'audio/wav']),
        'graph.instagram.com/*' => Http::response(['error' => ['code' => 100]], 400),
    ]);

    postMeta($this, metaBody('instagram', '17841400000000001', [
        customerMessage('3001', '17841400000000001', ['mid' => 'ig_audio', 'attachments' => [['type' => 'audio', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']]]]),
    ]), INSTAGRAM_SECRET)->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'ig_audio')->sole()->attachments->sole())
        ->status->toBe(AttachmentStatus::Available)
        ->mime_type->toBe('audio/wav');
});

test('a messaging policy enforcement notice is recorded on the connection', function () {
    postMeta($this, metaBody('page', '1122334455', [
        ['recipient' => ['id' => '1122334455'], 'timestamp' => now()->getTimestampMs(), 'policy-enforcement' => ['action' => 'block', 'reason' => 'The bot violated our Platform Policies.']],
    ]))->assertOk();

    $page = $this->page->refresh();

    expect($page->provider_signals['messaging_policy_enforcement']['value'])->toBe(['action' => 'block', 'reason' => 'The bot violated our Platform Policies.'])
        ->and($page->last_error_message)->toContain('Meta bloqueó el envío de mensajes de esta página')
        ->and(Message::query()->count())->toBe(0);
});

test('a watermark never advances a message Meta accepted in the same second or never accepted', function () {
    fakeNoProfile();
    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_in', 'text' => 'Hola'])]));
    $conversation = Conversation::query()->sole();
    $watermarkAt = now()->subMinute()->addMilliseconds(500);

    $sameSecond = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'm_same', 'sending_started_at' => now()->subMinutes(2), 'accepted_at' => now()->subMinute()]);
    $notAccepted = WhatsAppFixtures::outbound($conversation, ['status' => 'sent', 'whatsapp_message_id' => 'm_none', 'sending_started_at' => now()->subMinutes(2), 'accepted_at' => null]);
    $before = WhatsAppFixtures::outbound($conversation, ['status' => 'accepted', 'whatsapp_message_id' => 'm_before', 'sending_started_at' => now()->subMinutes(2), 'accepted_at' => now()->subMinutes(2)]);

    postMeta($this, metaBody('page', '1122334455', [
        ['sender' => ['id' => '2001'], 'recipient' => ['id' => '1122334455'], 'timestamp' => $watermarkAt->getTimestampMs(), 'delivery' => ['watermark' => $watermarkAt->getTimestampMs()]],
    ]))->assertOk();

    expect($before->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($sameSecond->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($notAccepted->refresh()->status)->toBe(MessageStatus::Sent);
});

test('inbound Messenger messages keep the PSID that wrote them', function () {
    fakeNoProfile();

    postMeta($this, metaBody('page', '1122334455', [customerMessage('2001', '1122334455', ['mid' => 'm_psid', 'text' => 'Hola'])]))->assertOk();

    expect(Message::query()->sole()->metadata['sender_external_id'] ?? null)->toBe('2001');
});
