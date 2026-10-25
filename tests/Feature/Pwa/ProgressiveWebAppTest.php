<?php

use App\Models\PushSubscription;
use App\Models\User;

test('the web app manifest is installable and its icons exist', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    $icons = collect($manifest['icons']);

    expect($manifest)->toMatchArray(['name' => 'Chat Manager', 'scope' => '/', 'display' => 'standalone'])
        ->and($manifest['start_url'])->toStartWith('/')
        ->and($icons->where('purpose', 'any')->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and($icons->where('purpose', 'maskable')->pluck('sizes')->all())->toContain('512x512');

    foreach ($icons as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();

        if ($icon['type'] === 'image/png') {
            [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
            expect("{$width}x{$height}")->toBe($icon['sizes']);
        }
    }

    expect(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180);
});

test('the offline page is static, in Spanish and offers a retry', function () {
    $html = (string) file_get_contents(public_path('offline.html'));

    expect($html)->toContain('<html lang="es">')
        ->toContain('No hay conexión a internet')
        ->toContain('Reintentar')
        ->toContain('prefers-color-scheme: dark')
        ->and(public_path('offline.js'))->toBeFile();
});

test('app pages link the manifest and the installable app metadata', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('name="theme-color"', false)
        ->assertSee('name="apple-mobile-web-app-capable" content="yes"', false)
        ->assertSee('viewport-fit=cover', false);
});

test('signing out clears the browser cache and forgets only this browser push subscription', function () {
    $user = User::factory()->agent()->create();
    $thisBrowser = PushSubscription::factory()->for($user)->endpoint('https://push.example.test/this-browser')->create();
    $otherDevice = PushSubscription::factory()->for($user)->endpoint('https://push.example.test/other-device')->create();
    $colleague = PushSubscription::factory()->for(User::factory()->agent()->create())->endpoint('https://push.example.test/this-browser-colleague')->create();

    $this->actingAs($user)
        ->post(route('logout'), ['push_endpoint' => 'https://push.example.test/this-browser'])
        ->assertHeader('Clear-Site-Data', '"cache"');

    $this->assertGuest();
    expect(PushSubscription::query()->find($thisBrowser->id))->toBeNull()
        ->and(PushSubscription::query()->find($otherDevice->id))->not->toBeNull()
        ->and(PushSubscription::query()->find($colleague->id))->not->toBeNull();
});

test('signing out without a push endpoint keeps every subscription', function () {
    $user = User::factory()->agent()->create();
    PushSubscription::factory()->for($user)->create();

    $this->actingAs($user)->post(route('logout'))->assertHeader('Clear-Site-Data', '"cache"');

    expect(PushSubscription::query()->count())->toBe(1);
});

/**
 * Body of a top-level function of public/sw.js (up to the next top-level declaration).
 */
function serviceWorkerFunction(string $source, string $name): string
{
    expect(preg_match('/(?:async )?function '.$name.'\(.*?\n}\n/s', $source, $match))->toBe(1, "sw.js has no function {$name}");

    return $match[0];
}

test('the service worker never caches navigations, Inertia responses or private routes', function () {
    $source = (string) file_get_contents(public_path('sw.js'));

    $navigation = serviceWorkerFunction($source, 'networkOnlyNavigation');
    $cacheable = serviceWorkerFunction($source, 'isCacheable');
    $cacheFirst = serviceWorkerFunction($source, 'cacheFirst');

    expect($navigation)->not->toContain('cache.put')->not->toContain('caches.open')
        ->and($source)->toContain("if (request.mode === 'navigate') {\n        event.respondWith(networkOnlyNavigation(request));")
        ->and($cacheable)
        ->toContain('url.origin !== self.location.origin')
        ->toContain("request.method !== 'GET'")
        ->toContain("request.headers.has('X-Inertia')")
        ->toContain("url.search !== ''")
        ->toContain('url.pathname.startsWith(IMMUTABLE_PREFIX)')
        ->and($source)->toContain("const IMMUTABLE_PREFIX = '/build/assets/';")
        ->and(substr_count($source, 'cache.put('))->toBe(1)
        ->and($cacheFirst)->toContain("response.ok && response.type === 'basic'");

    preg_match('/const PRECACHE = \[(.*?)\];/s', $source, $precache);
    preg_match_all("/'([^']+)'/", $precache[1] ?? '', $paths);

    expect($paths[1])->not->toBeEmpty();

    foreach ($paths[1] as $path) {
        expect($path)->toMatch('/\.(html|js|webmanifest|svg|ico|png)$/')
            ->and($path)->not->toMatch('#^/(api|broadcasting|conversations|dashboard|notifications|login)#');
    }
});

test('the service worker handles updates and always shows a notification for a push', function () {
    $source = (string) file_get_contents(public_path('sw.js'));
    $push = serviceWorkerFunction($source, 'handlePush');

    expect($source)->toContain("type === 'SKIP_WAITING'")->toContain('self.skipWaiting()')
        ->and($source)->toContain("type === 'CLEAR_CACHES'")
        ->and($push)->toContain('showNotification(')
        ->and(strpos($push, 'return'))->toBeFalse()
        ->and(serviceWorkerFunction($source, 'summaryText'))->toContain("cache: 'no-store'")
        ->and($source)->toContain('pruneAssets()');
});

test('the manifest describes every channel and matches the light theme color of the pages', function () {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    $layout = (string) file_get_contents(resource_path('views/app.blade.php'));

    expect($manifest['description'])->toContain('WhatsApp')->toContain('Messenger')->toContain('Instagram')->toContain('chat web')
        ->and($layout)->toContain('<meta name="theme-color" content="'.$manifest['theme_color'].'" media="(prefers-color-scheme: light)">');
});
