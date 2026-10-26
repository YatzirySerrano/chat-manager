<?php

use App\Models\PushSubscription;
use App\Models\User;

test('signing out answers without Clear-Site-Data so the browser does not hold the response', function () {
    $response = $this->actingAs(User::factory()->create())->post(route('logout'));

    $response->assertRedirect(route('home'))
        ->assertHeaderMissing('Clear-Site-Data');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('an Inertia sign out is a plain redirect that ends on the login page', function () {
    $this->actingAs(User::factory()->create());

    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('logout'))
        ->assertStatus(302)
        ->assertRedirect(route('home'))
        ->assertHeaderMissing('Clear-Site-Data');

    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the closed session cannot be reused to open protected pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $sessionId = session()->getId();

    $this->post(route('logout'));

    expect(session()->getId())->not->toBe($sessionId);
    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('signing out forgets only the push subscription of this browser', function () {
    $user = User::factory()->agent()->create();
    $thisBrowser = PushSubscription::factory()->for($user)->endpoint('https://push.example.test/this-browser')->create();
    $otherDevice = PushSubscription::factory()->for($user)->endpoint('https://push.example.test/other-device')->create();
    $colleague = PushSubscription::factory()->for(User::factory()->agent()->create())->endpoint('https://push.example.test/colleague-browser')->create();

    $this->actingAs($user)
        ->post(route('logout'), ['push_endpoint' => 'https://push.example.test/this-browser'])
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect(PushSubscription::query()->find($thisBrowser->id))->toBeNull()
        ->and(PushSubscription::query()->find($otherDevice->id))->not->toBeNull()
        ->and(PushSubscription::query()->find($colleague->id))->not->toBeNull();
});

test('signing out without a push endpoint still closes the session and keeps every subscription', function () {
    $user = User::factory()->agent()->create();
    PushSubscription::factory()->for($user)->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect(PushSubscription::query()->count())->toBe(1);
});
