<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

test('the passkeys feature is disabled', function () {
    expect(Features::enabled(Features::passkeys()))->toBeFalse();
});

test('passkey endpoints are not registered', function (string $method, string $uri) {
    $this->json($method, $uri)->assertNotFound();
})->with([
    'login options' => ['GET', '/passkeys/login/options'],
    'login' => ['POST', '/passkeys/login'],
    'confirmation options' => ['GET', '/passkeys/confirm/options'],
    'confirmation' => ['POST', '/passkeys/confirm'],
    'well-known endpoints' => ['GET', '/.well-known/passkey-endpoints'],
]);

test('passkey management endpoints are not registered for signed in users', function (string $method, string $uri) {
    $this->actingAs(User::factory()->create())
        ->json($method, $uri)
        ->assertNotFound();
})->with([
    'registration options' => ['GET', '/user/passkeys/options'],
    'registration' => ['POST', '/user/passkeys'],
    'deletion' => ['DELETE', '/user/passkeys/1'],
]);

test('the login page only offers email and password sign in', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->has('canResetPassword')
            ->missing('passkeys')
            ->missing('canUsePasskeys'),
        );
});

test('users still sign in with email and password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('stored passkeys are kept', function () {
    expect(Schema::hasTable('passkeys'))->toBeTrue();
});
