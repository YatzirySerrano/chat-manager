<?php

use App\Enums\WebhookEventStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Connection;
use App\Models\WebhookEvent;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;

test('the readiness probe answers ok without any detail', function () {
    app(SchedulerHeartbeat::class)->beat();

    $response = $this->getJson('/health/ready')->assertOk();

    expect($response->json())->toBe(['status' => 'ok'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
});

test('the readiness verdict is reused for a few seconds so public traffic cannot multiply the checks', function () {
    app(SchedulerHeartbeat::class)->beat();
    config(['operations.readiness.cache_seconds' => 10]);

    $this->getJson('/health/ready')->assertOk();

    Cache::forever(config('operations.scheduler.heartbeat_key'), now()->subHour()->getTimestamp());

    $this->getJson('/health/ready')->assertOk();

    $this->travel(11)->seconds();

    $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'fail']);
});

test('the readiness probe fails when the scheduler stopped, without saying why', function () {
    Cache::forever(config('operations.scheduler.heartbeat_key'), now()->subHour()->getTimestamp());

    $response = $this->getJson('/health/ready')->assertStatus(503);

    expect($response->json())->toBe(['status' => 'fail'])
        ->and($response->getContent())->not->toContain('scheduler')
        ->and($response->getContent())->not->toContain('programador');
});

test('the readiness probe fails on a critical webhook backlog', function () {
    app(SchedulerHeartbeat::class)->beat();
    config(['operations.webhooks.critical_backlog' => 1]);
    $connection = Connection::factory()->create();
    WebhookEvent::query()->create([
        'connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'x'),
        'payload' => '{}',
        'status' => WebhookEventStatus::Received,
        'attempts' => 0,
        'received_at' => now()->subMinutes(30),
    ]);

    $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'fail']);
});

test('the liveness probe keeps working', function () {
    $this->get('/up')->assertOk();
});

test('responses carry hardening headers and a correlation id', function () {
    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('X-Request-Id'))->not->toBeEmpty()
        // Development and tests: no CSP (the Vite dev server serves scripts from another origin) and no HSTS.
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('a well formed request id from the proxy is kept', function () {
    $this->get('/up', ['X-Request-Id' => 'nginx-req-12345678'])
        ->assertHeader('X-Request-Id', 'nginx-req-12345678');

    expect($this->get('/up', ['X-Request-Id' => 'bad id <script>'])->headers->get('X-Request-Id'))
        ->not->toBe('bad id <script>');
});

test('when enabled, the content security policy uses a nonce that the page scripts carry', function () {
    config(['operations.http.csp' => true]);
    Vite::useHotFile(storage_path('framework/testing-no-vite-hot-file'));

    $response = $this->get('/login')->assertOk();
    $policy = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([^']+)'/", $policy, $matches);

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($policy)->toContain("frame-ancestors 'self'")
        ->and($policy)->toContain("object-src 'none'")
        ->and($policy)->not->toContain('unsafe-eval')
        ->and($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

test('the policy allows the real-time server only when Reverb is enabled', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.realtime.client' => ['host' => 'chat.example.com', 'port' => 443, 'scheme' => 'https', 'path' => ''],
    ]);

    expect(app(SecurityHeaders::class)->policy('abc'))->toContain("connect-src 'self' wss://chat.example.com");

    config(['broadcasting.default' => 'null']);

    expect(app(SecurityHeaders::class)->policy('abc'))->toContain("connect-src 'self';");
});
