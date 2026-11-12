<?php

use App\Models\Connection;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\WhatsAppFixtures;

/*
| Webhook throttling never lets unsigned traffic block real provider
| deliveries, even when every request reaches Laravel with the same address
| (a reverse proxy without TRUSTED_PROXIES): credentials are checked first and
| only failures are counted.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config([
        'services.meta.app_secret' => 'facebook-app-secret-throttle',
        'services.meta.instagram_app_secret' => null,
        'services.meta.webhook_verify_token' => 'verify-token-throttle-123456',
        'services.meta.webhook_invalid_signatures_per_minute' => 5,
    ]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function throttleOrderingPostMeta(mixed $test, array $payload, ?string $signature = null): TestResponse
{
    $body = (string) json_encode($payload);

    return $test->call('POST', route('webhooks.meta.receive'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'facebook-app-secret-throttle'),
    ], content: $body);
}

test('a flood of unsigned Meta bodies from the proxy address never blocks signed deliveries', function () {
    $payload = ['object' => 'page', 'entry' => [['id' => '5566778899', 'time' => 1, 'messaging' => []]]];

    foreach (range(1, 5) as $attempt) {
        throttleOrderingPostMeta($this, $payload, 'sha256=forged')->assertUnauthorized();
    }

    foreach (range(1, 3) as $attempt) {
        throttleOrderingPostMeta($this, $payload, 'sha256=forged')->assertTooManyRequests();
        throttleOrderingPostMeta($this, $payload)->assertOk();
    }

    throttleOrderingPostMeta($this, $payload, '')->assertTooManyRequests();
});

test('wrong Meta verify tokens are throttled while the configured token keeps answering', function () {
    $url = route('webhooks.meta.verify');

    foreach (range(1, 5) as $attempt) {
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=guess-'.$attempt.'&hub.challenge=42')->assertForbidden();
    }

    $this->get($url.'?hub.mode=subscribe&hub.verify_token=guess-6&hub.challenge=42')->assertTooManyRequests();
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-token-throttle-123456&hub.challenge=42')
        ->assertOk()
        ->assertContent('42');
});

test('unsigned WhatsApp bodies from the same address never block the signed deliveries of the number', function () {
    $connection = Connection::factory()->verified()->create(['business_account_id' => '102290129340398']);
    $payload = whatsappWebhook($connection, [whatsappTextMessage('5215512345678', 'Hola', id: 'wamid.throttle-ordering')]);

    foreach (range(1, 40) as $attempt) {
        WhatsAppFixtures::post($this, $connection, $payload, 'sha256=forged')->assertUnauthorized();
    }

    WhatsAppFixtures::post($this, $connection, $payload)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
});
