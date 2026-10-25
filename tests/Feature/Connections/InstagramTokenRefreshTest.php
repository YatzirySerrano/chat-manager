<?php

use App\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| meta:refresh-instagram-tokens renews Instagram Login tokens before their
| 60 days expire. Every call to Meta is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.instagram_app_secret' => 'instagram-app-secret-value']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
});

test('tokens expiring within ten days and older than a day are refreshed; others are left alone', function () {
    $soon = Connection::factory()->instagram()->create(['access_token' => 'IG-SOON']);
    $soon->forceFill(['token_expires_at' => now()->addDays(5), 'authorized_at' => now()->subDays(55)])->save();
    $fresh = Connection::factory()->instagram()->create(['access_token' => 'IG-FRESH']);
    $fresh->forceFill(['token_expires_at' => now()->addDays(5), 'authorized_at' => now()->subHours(2)])->save();
    $later = Connection::factory()->instagram()->create(['access_token' => 'IG-LATER']);
    $page = Connection::factory()->messenger()->create();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'IG-RENEWED', 'token_type' => 'bearer', 'expires_in' => 5184000])]);

    $this->artisan('meta:refresh-instagram-tokens')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ($request->data()['grant_type'] ?? null) === 'ig_refresh_token' && ($request->data()['access_token'] ?? null) === 'IG-SOON');
    expect($soon->refresh())->access_token->toBe('IG-RENEWED')
        ->and($soon->token_expires_at->greaterThan(now()->addDays(59)))->toBeTrue()
        ->and($fresh->refresh()->access_token)->toBe('IG-FRESH')
        ->and($later->refresh()->access_token)->toBe('IG-LATER')
        ->and($page->refresh()->access_token)->not->toBeNull();
});

test('an expired or revoked token asks for a new authorization', function () {
    $expired = Connection::factory()->instagram()->create();
    $expired->forceFill(['token_expires_at' => now()->subDay()])->save();
    $revoked = Connection::factory()->instagram()->create();
    $revoked->forceFill(['token_expires_at' => now()->addDays(3), 'authorized_at' => now()->subDays(57)])->save();
    Http::fake(['graph.instagram.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400)]);

    $this->artisan('meta:refresh-instagram-tokens')->assertSuccessful();

    expect($expired->refresh()->credentials_error)->toContain('venció')
        ->and($revoked->refresh()->credentials_error)->toContain('Vuelve a autorizar');
});

test('a token whose expiry Meta never reported is refreshed and gets the 60-day lifetime', function () {
    $unknown = Connection::factory()->instagram()->create(['access_token' => 'IG-UNKNOWN']);
    $unknown->forceFill(['token_expires_at' => null, 'authorized_at' => now()->subDays(20)])->save();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'IG-RENEWED', 'token_type' => 'bearer'])]);

    $this->artisan('meta:refresh-instagram-tokens')->assertSuccessful();

    expect($unknown->refresh())->access_token->toBe('IG-RENEWED')
        ->and($unknown->token_expires_at->toIso8601String())->toBe(now()->addDays(60)->toIso8601String());
});

test('a connection disconnected or re-authorized during the refresh keeps its newer state', function (?string $newToken) {
    $connection = Connection::factory()->instagram()->create(['access_token' => 'IG-OLD']);
    $connection->forceFill(['token_expires_at' => now()->addDays(3), 'authorized_at' => now()->subDays(57)])->save();
    Http::fake(['graph.instagram.com/refresh_access_token*' => function () use ($connection, $newToken) {
        // The administrator acts while Meta answers.
        DB::table('connections')->where('id', $connection->id)->update(['access_token' => $newToken === null ? null : encrypt($newToken, false)]);

        return Http::response(['access_token' => 'IG-RENEWED', 'expires_in' => 5184000]);
    }]);

    $this->artisan('meta:refresh-instagram-tokens')->assertSuccessful();

    expect($connection->refresh()->access_token)->toBe($newToken)
        ->and($connection->provider_signals['token_refresh'] ?? null)->toBeNull();
})->with([
    'disconnected' => [null],
    're-authorized' => ['IG-REAUTHORIZED'],
]);
