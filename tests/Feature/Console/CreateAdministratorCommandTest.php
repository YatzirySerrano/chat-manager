<?php

use App\Models\User;

test('an administrator can be created from the console', function () {
    $this->artisan('app:create-administrator', ['--name' => 'Responsable TI', '--email' => 'TI@Empresa.test'])
        ->expectsQuestion('Contraseña', 'contraseña-segura')
        ->assertSuccessful();

    $user = User::query()->where('email', 'ti@empresa.test')->sole();

    expect($user->hasRootAuthority())->toBeTrue()
        ->and($user->is_active)->toBeTrue();
});

test('the console command rejects a duplicated email', function () {
    User::factory()->create(['email' => 'ti@empresa.test']);

    $this->artisan('app:create-administrator', ['--name' => 'Responsable TI', '--email' => 'ti@empresa.test'])
        ->expectsQuestion('Contraseña', 'contraseña-segura')
        ->assertFailed();

    expect(User::query()->where('email', 'ti@empresa.test')->count())->toBe(1);
});
