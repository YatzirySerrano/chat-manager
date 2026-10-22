<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('users cannot delete their own account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    $this->assertModelExists($user);
});

test('the profile page no longer offers account deletion', function () {
    $user = User::factory()->create();

    expect(Route::has('profile.destroy'))->toBeFalse();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee('delete-user-button');
});

test('saving the current profile writes nothing and says so', function () {
    $user = User::factory()->create(['name' => 'Laura Pérez', 'email' => 'laura@empresa.test']);
    $updatedAt = $user->updated_at;
    $this->travel(1)->hours();

    $writes = databaseWritesDuring(fn () => $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => ' Laura Pérez ', 'email' => 'laura@empresa.test '])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']));

    expect($writes)->toBe([])
        ->and($user->refresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a profile change is confirmed in Spanish', function () {
    $user = User::factory()->create(['name' => 'Laura Pérez']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Laura Gómez', 'email' => $user->email])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Perfil actualizado.']);
});
