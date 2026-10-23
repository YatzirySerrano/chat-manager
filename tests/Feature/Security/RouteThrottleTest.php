<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
| "throttle:attempts,minutes" keeps one counter per route and user: traffic
| on one throttled route (the bell polling its summary, channel
| authorizations) never uses up the allowance of another (contact import,
| exports), while each route still enforces its own limit.
*/

beforeEach(function () {
    Route::middleware(['web', 'auth', 'throttle:2,1'])->group(function () {
        Route::get('_throttle-test/polled', fn () => 'ok')->name('throttle-test.polled');
        Route::post('_throttle-test/action', fn () => 'ok')->name('throttle-test.action');
    });

    $this->user = User::factory()->create();
});

test('a route keeps its own limit', function () {
    $this->actingAs($this->user);

    $this->get('/_throttle-test/polled')->assertOk();
    $this->get('/_throttle-test/polled')->assertOk();
    $this->get('/_throttle-test/polled')->assertTooManyRequests();
});

test('traffic on one throttled route does not exhaust another route', function () {
    $this->actingAs($this->user);

    $this->get('/_throttle-test/polled')->assertOk();
    $this->get('/_throttle-test/polled')->assertOk();
    $this->get('/_throttle-test/polled')->assertTooManyRequests();

    $this->post('/_throttle-test/action')->assertOk();
});

test('the import upload is not blocked by the notification summary polling', function () {
    $this->actingAs($this->user);

    foreach (range(1, 12) as $attempt) {
        $this->getJson(route('notifications.summary'))->assertOk();
    }

    $this->post(route('contacts.import.store'))->assertStatus(403);
});

test('password recovery is limited per IP on each of its routes', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => "nadie{$attempt}@empresa.test"])->assertSessionHasNoErrors();
    }

    $this->post(route('password.email'), ['email' => 'otra@empresa.test'])->assertTooManyRequests();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.update'), ['token' => 'invalido', 'email' => "nadie{$attempt}@empresa.test", 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertSessionHasErrors('email');
    }

    $this->post(route('password.update'), ['token' => 'invalido', 'email' => 'otra@empresa.test', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertTooManyRequests();

    // Another address is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])
        ->post(route('password.email'), ['email' => 'otra@empresa.test'])
        ->assertSessionHasNoErrors();
});
