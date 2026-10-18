<?php

use App\Models\User;

test('public registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruso',
        'email' => 'intruso@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'intruso@example.com']);
});

test('deactivated users cannot log in', function () {
    $user = User::factory()->agent()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => 'Tu cuenta está desactivada. Contacta a un administrador.']);

    $this->assertGuest();
});

test('deactivated users do not learn their status with a wrong password', function () {
    $user = User::factory()->agent()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);
});

test('the session of a deactivated user is terminated on the next request', function () {
    $user = User::factory()->agent()->inactive()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a successful login records the last access time', function () {
    $user = User::factory()->agent()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect($user->refresh()->last_login_at)->not->toBeNull();
});
