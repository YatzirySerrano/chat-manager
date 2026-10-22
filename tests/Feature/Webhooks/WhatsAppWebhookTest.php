<?php

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Actions\Messaging\StoreWebhookDelivery;
use App\Console\Commands\WhatsAppMaintenance;
use App\Enums\ConnectionHealth;
use App\Enums\WebhookEventStatus;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Connection;
use App\Models\Message;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppFixtures;

/*
| Public WhatsApp Cloud API webhook: verification challenge, signature,
| idempotent storage and asynchronous processing. Nothing reaches Meta.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    // Same business account id as the shared whatsappWebhook() envelope.
    $this->connection = Connection::factory()->verified()->create(['business_account_id' => '102290129340398']);
});

test('Meta verification challenge is answered only with the connection verify token', function () {
    Event::fake([ConnectionHealthChanged::class]);
    $url = route('webhooks.whatsapp.verify', ['webhookKey' => $this->connection->webhook_key]);

    $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1158201444')
        ->assertForbidden()
        ->assertSee('', false);
    $this->get($url.'?hub.mode=unsubscribe&hub.verify_token='.$this->connection->webhook_verify_token.'&hub.challenge=1158201444')
        ->assertForbidden();

    expect($this->connection->refresh()->webhook_verified_at)->toBeNull();

    $this->get($url.'?hub.mode=subscribe&hub.verify_token='.$this->connection->webhook_verify_token.'&hub.challenge=1158201444')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent('1158201444');

    expect($this->connection->refresh()->webhook_verified_at)->not->toBeNull()
        ->and($this->connection->health())->toBe(ConnectionHealth::Receiving);
    Event::assertDispatched(ConnectionHealthChanged::class, fn (ConnectionHealthChanged $event): bool => $event->connectionId === $this->connection->id
        && $event->health === 'receiving' && $event->previousHealth === 'credentials_verified');
});

test('a challenge that could carry markup is never echoed back', function () {
    $url = route('webhooks.whatsapp.verify', ['webhookKey' => $this->connection->webhook_key]);

    $this->get($url.'?hub.mode=subscribe&hub.verify_token='.$this->connection->webhook_verify_token.'&hub.challenge=%3Cscript%3E')
        ->assertForbidden()
        ->assertDontSee('script');
});

test('unknown or deleted connections look the same: not found', function () {
    $this->get('/webhooks/whatsapp/'.str_repeat('a', 48).'?hub.mode=subscribe&hub.verify_token=x&hub.challenge=1')->assertNotFound();
    $this->post('/webhooks/whatsapp/'.str_repeat('a', 48))->assertNotFound();

    $this->connection->forceFill(['deleted_at' => now(), 'is_active' => false])->save();

    $this->get(route('webhooks.whatsapp.verify', ['webhookKey' => $this->connection->webhook_key]).'?hub.mode=subscribe&hub.verify_token='.$this->connection->webhook_verify_token.'&hub.challenge=1')
        ->assertNotFound();
    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678')]))->assertNotFound();
});

test('every connection gets its own random webhook key, never its id', function () {
    $other = Connection::factory()->create();

    expect($this->connection->webhook_key)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($other->webhook_key)->not->toBe($this->connection->webhook_key)
        ->and(route('webhooks.whatsapp.receive', ['webhookKey' => $other->webhook_key]))->toEndWith('/webhooks/whatsapp/'.$other->webhook_key)
        ->and($other->webhook_key)->not->toBe((string) $other->id);
});

test('a signed delivery is stored, answered at once and processed into a message', function () {
    Event::fake([MessageRecorded::class]);
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola, quiero información', id: 'wamid.webhook.1')], ['5215512345678' => 'Laura']);

    $response = WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk()->assertContent('');

    expect($response->headers->getCookies())->toBe([]);

    $event = WebhookEvent::query()->sole();
    expect($event)
        ->status->toBe(WebhookEventStatus::Processed)
        ->fields->toBe('messages')
        ->attempts->toBe(1)
        ->summary->toMatchArray(['messages' => 1])
        ->and($event->payload)->toBe(json_encode($payload))
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.webhook.1')->sole()->body)->toBe('Hola, quiero información')
        ->and($this->connection->refresh()->last_webhook_at)->not->toBeNull()
        ->and($this->connection->last_inbound_at)->not->toBeNull();

    Event::assertDispatchedTimes(MessageRecorded::class, 1);
});

test('a repeated delivery of the same body is stored once and changes nothing', function () {
    Event::fake([MessageRecorded::class]);
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.repeated')]);

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->attempts)->toBe(1)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.repeated')->count())->toBe(1);
    Event::assertDispatchedTimes(MessageRecorded::class, 1);
});

test('the same message inside a different body is still received once', function () {
    $message = whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.rebatched');

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [$message]))->assertOk();
    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [$message, whatsappTextMessage('5215512345678', 'Otro', id: 'wamid.second')]))->assertOk();

    expect(WebhookEvent::query()->count())->toBe(2)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.rebatched')->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2);
});

test('invalid, missing or foreign signatures are refused without details and store nothing', function (Closure $signature, int $status) {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.forged')]);
    $body = (string) json_encode($payload);

    WhatsAppFixtures::post($this, $this->connection, $body, $signature($body, $this->connection))
        ->assertStatus($status)
        ->assertContent('');

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0);
})->with([
    'wrong secret' => [fn (string $body) => WhatsAppFixtures::signature($body, 'another-secret'), 401],
    'missing' => [fn () => '', 401],
    'sha1 header' => [fn (string $body, Connection $connection) => 'sha1='.hash_hmac('sha1', $body, (string) $connection->app_secret), 401],
    'body changed after signing' => [fn (string $body, Connection $connection) => WhatsAppFixtures::signature($body.' ', (string) $connection->app_secret), 401],
]);

test('a connection without app secret cannot authenticate deliveries', function () {
    $this->connection->forceFill(['app_secret' => null])->save();
    $body = (string) json_encode(whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678')]));

    WhatsAppFixtures::post($this, $this->connection, $body, WhatsAppFixtures::signature($body, ''))->assertForbidden();

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('malformed and oversized bodies are refused', function () {
    WhatsAppFixtures::post($this, $this->connection, 'not json')->assertStatus(400);

    config(['whatsapp.webhooks.max_body_bytes' => 100]);
    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', str_repeat('a', 200))]))->assertStatus(413);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('deliveries are rate limited per webhook URL', function () {
    config(['whatsapp.webhooks.rate_limit_per_minute' => 2]);
    $other = Connection::factory()->create();
    $payload = fn (Connection $connection) => whatsappWebhook($connection, [whatsappTextMessage('5215512345678')]);

    WhatsAppFixtures::post($this, $this->connection, $payload($this->connection))->assertOk();
    WhatsAppFixtures::post($this, $this->connection, $payload($this->connection))->assertOk();
    WhatsAppFixtures::post($this, $this->connection, $payload($this->connection))->assertTooManyRequests();
    WhatsAppFixtures::post($this, $other, $payload($other))->assertOk();
});

test('messages addressed to another phone number are not attached to the webhook connection', function () {
    $other = Connection::factory()->create();

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($other, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.misrouted')]))->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'wamid.misrouted')->exists())->toBeFalse()
        ->and(WebhookEvent::query()->sole()->status)->toBe(WebhookEventStatus::Processed);
});

test('changes of another business account are ignored', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.other-account')]);
    $payload['entry'][0]['id'] = '999999999999999';

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();

    expect(Message::query()->count())->toBe(0);
});

test('a delivery whose processing failed is kept as failed and processed again when Meta repeats it', function () {
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.retry')]);
    $failing = Mockery::mock(ProcessWebhookPayload::class);
    $failing->shouldReceive('handle')->andThrow(new RuntimeException('database unavailable'));
    app()->instance(ProcessWebhookPayload::class, $failing);

    $this->withoutExceptionHandling();
    expect(fn () => WhatsAppFixtures::post($this, $this->connection, $payload))->toThrow(RuntimeException::class);

    expect(WebhookEvent::query()->sole())
        ->status->toBe(WebhookEventStatus::Failed)
        ->error->toContain('database unavailable');

    app()->forgetInstance(ProcessWebhookPayload::class);
    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();

    expect(WebhookEvent::query()->sole())
        ->status->toBe(WebhookEventStatus::Processed)
        ->error->toBeNull()
        ->attempts->toBe(2)
        ->and(Message::query()->where('whatsapp_message_id', 'wamid.retry')->exists())->toBeTrue();
});

test('an inactive connection keeps receiving customer messages so none are lost', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    WhatsAppFixtures::post($this, $this->connection, whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.paused')]))->assertOk();

    expect(Message::query()->where('whatsapp_message_id', 'wamid.paused')->exists())->toBeTrue();
});

test('old webhook logs are pruned after their retention, recent and pending ones are kept', function () {
    $make = fn (WebhookEventStatus $status, int $daysAgo) => WebhookEvent::query()->create([
        'connection_id' => $this->connection->id,
        'payload_hash' => hash('sha256', uniqid('', true)),
        'payload' => '{}',
        'status' => $status,
        'received_at' => now()->subDays($daysAgo),
    ]);
    $old = $make(WebhookEventStatus::Processed, 31);
    $recent = $make(WebhookEventStatus::Processed, 2);
    $failedRecent = $make(WebhookEventStatus::Failed, 40);
    $failedOld = $make(WebhookEventStatus::Failed, 91);

    $this->artisan('whatsapp:maintenance', ['task' => 'prune-webhooks'])->assertSuccessful();

    expect(WebhookEvent::query()->pluck('id')->sort()->values()->all())->toBe([$recent->id, $failedRecent->id]);
});

test('a stored delivery whose job was lost is queued again on redelivery and by the recovery task', function () {
    Queue::fake();
    $payload = whatsappWebhook($this->connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.lost')]);

    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    Queue::assertPushedTimes(ProcessWhatsAppWebhook::class, 1);

    $this->travel(StoreWebhookDelivery::STALE_AFTER_SECONDS + 10)->seconds();
    WhatsAppFixtures::post($this, $this->connection, $payload)->assertOk();
    Queue::assertPushedTimes(ProcessWhatsAppWebhook::class, 2);

    $this->artisan('whatsapp:maintenance', ['task' => 'recover-webhooks'])->assertSuccessful();
    Queue::assertPushedTimes(ProcessWhatsAppWebhook::class, 2);

    $this->travel(WhatsAppMaintenance::RECOVER_AFTER_MINUTES + 1)->minutes();
    $this->artisan('whatsapp:maintenance', ['task' => 'recover-webhooks'])->assertSuccessful();
    Queue::assertPushedTimes(ProcessWhatsAppWebhook::class, 3);

    // Attempts exhausted: it waits for a manual review instead of looping.
    WebhookEvent::query()->sole()->forceFill(['attempts' => 5])->save();
    $this->travel(WhatsAppMaintenance::RECOVER_AFTER_MINUTES + 1)->minutes();
    $this->artisan('whatsapp:maintenance', ['task' => 'recover-webhooks'])->assertSuccessful();
    Queue::assertPushedTimes(ProcessWhatsAppWebhook::class, 3);
    expect(WebhookEvent::query()->count())->toBe(1);
});
