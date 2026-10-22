<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;

test('the first superadministrator can be created from the console', function () {
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

test('the console only provisions the first superadministrator', function (bool $existingIsActive) {
    $existing = User::factory()->superAdministrator()->create(['is_active' => $existingIsActive]);

    $this->artisan('app:create-administrator', ['--name' => 'Otra Persona', '--email' => 'otra@empresa.test'])
        ->expectsOutputToContain('Ya existe un superadministrador')
        ->assertFailed();

    expect(User::query()->where('email', 'otra@empresa.test')->exists())->toBeFalse()
        ->and(User::query()->whereKeyNot($existing->id)->count())->toBe(0);
})->with([
    'active' => true,
    'deleted' => false,
]);

test('provisioning the superadministrator is audited as a restricted entry', function () {
    $this->artisan('app:create-administrator', ['--name' => 'Responsable TI', '--email' => 'ti@empresa.test'])
        ->expectsQuestion('Contraseña', 'contraseña-segura')
        ->assertSuccessful();

    $entry = AuditLog::query()->where('action', AuditAction::UserCreated)->sole();

    expect($entry)
        ->actor_name->toBe('Sistema')
        ->restricted->toBeTrue()
        ->and($entry->properties)->toMatchArray(['role' => 'Superadministrador']);
});
