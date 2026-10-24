<?php

use App\Services\WebChat\FrameTicket;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;
use Tests\TestCase;

/*
| The widget frame (GET /widget/v1/frame/{publicKey}): the only page other
| origins may frame (its own CSP lists the allowed origins, without
| X-Frame-Options); it carries a frame ticket only when the Referer origin is
| allowed, preview only works from the application itself, and the
| embedded configuration never exposes secrets or tokens.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->connection = WebChatFixtures::connection(['allowed_origins' => ['https://tienda.test', 'http://localhost:3000']]);
    $this->widget = $this->connection->webChatWidget;
});

/**
 * The configuration the frame embeds for its script.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function widgetFrameConfig(TestResponse $response): array
{
    preg_match('#<script type="application/json" id="cm-config"[^>]*>(.*?)</script>#s', (string) $response->getContent(), $match);

    return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function requestWidgetFrame(TestCase $test, string $publicKey, ?string $referer, array $query = []): TestResponse
{
    $headers = $referer === null ? [] : ['Referer' => $referer];

    return $test->get(route('widget.frame', ['publicKey' => $publicKey, ...$query]), $headers);
}

test('an allowed host gets the frame with its own frame-ancestors and a ticket bound to its origin', function () {
    $response = requestWidgetFrame($this, $this->widget->public_key, 'https://tienda.test/')
        ->assertOk()
        ->assertHeaderMissing('X-Frame-Options')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'self' https://tienda.test http://localhost:3000;")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");

    $config = widgetFrameConfig($response);

    expect($config['state'])->toBe('ready')
        ->and($config['host_origin'])->toBe('https://tienda.test')
        ->and($config['allowed_origins'])->toBe(['https://tienda.test', 'http://localhost:3000'])
        ->and(app(FrameTicket::class)->verify($this->widget, $config['ticket']))->toBe('https://tienda.test')
        ->and($config['endpoints']['sessions'])->toBe('/api/v1/widget/'.$this->widget->public_key.'/sessions')
        ->and($config['appearance']['title'])->toBe($this->widget->title);
});

test('the rest of the application keeps refusing to be framed by other origins', function () {
    $this->get('/login')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('an unknown key or the key of a deleted connection is a 404', function () {
    requestWidgetFrame($this, str_repeat('a', 48), 'https://tienda.test/')->assertNotFound();

    $this->connection->forceFill(['deleted_at' => now()])->save();

    requestWidgetFrame($this, $this->widget->public_key, 'https://tienda.test/')->assertNotFound();
});

test('a host that is not allowed, or no referer, gets the not authorized state without a ticket', function (?string $referer) {
    $response = requestWidgetFrame($this, $this->widget->public_key, $referer)->assertOk();
    $config = widgetFrameConfig($response);

    expect($config['state'])->toBe('unauthorized')
        ->and($config['ticket'])->toBeNull()
        ->and($config['host_origin'])->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self' https://tienda.test http://localhost:3000;");
})->with([
    'other site' => 'https://otro-sitio.test/pagina',
    'subdomain' => 'https://www.tienda.test/',
    'other scheme' => 'http://tienda.test/',
    'no referer' => null,
]);

test('preview is only honored when the referer is the application itself', function () {
    $appOrigin = rtrim(url('/'), '/');

    $preview = requestWidgetFrame($this, $this->widget->public_key, $appOrigin.'/connections/1/edit', ['preview' => 1])->assertOk();
    $config = widgetFrameConfig($preview);

    expect($config['state'])->toBe('preview')
        ->and($config['ticket'])->toBeNull()
        ->and($config['host_origin'])->toBe($appOrigin)
        ->and($config['allowed_origins'])->toBe([$appOrigin])
        ->and($preview->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self';");

    $fromHost = widgetFrameConfig(requestWidgetFrame($this, $this->widget->public_key, 'https://tienda.test/', ['preview' => 1]));

    expect($fromHost['state'])->toBe('ready')
        ->and($fromHost['host_origin'])->toBe('https://tienda.test');
});

test('an inactive connection shows the offline state without a ticket', function () {
    $this->connection->forceFill(['is_active' => false])->save();

    $config = widgetFrameConfig(requestWidgetFrame($this, $this->widget->public_key, 'https://tienda.test/')->assertOk());

    expect($config['state'])->toBe('offline')
        ->and($config['ticket'])->toBeNull()
        ->and($config['host_origin'])->toBe('https://tienda.test');
});

test('the page never includes the identity secret or any token', function () {
    ['token' => $visitorToken] = WebChatFixtures::session($this->connection);
    ['plain' => $apiToken] = WebChatFixtures::apiToken(['connection_ids' => [$this->connection->id]]);
    $secret = (string) $this->widget->fresh()->identity_secret;

    requestWidgetFrame($this, $this->widget->public_key, WebChatVisitor::ORIGIN.'/')
        ->assertOk()
        ->assertDontSee($secret, false)
        ->assertDontSee('identity_secret', false)
        ->assertDontSee($visitorToken, false)
        ->assertDontSee('wvs_', false)
        ->assertDontSee($apiToken, false)
        ->assertDontSee('cm_live_', false);
});
