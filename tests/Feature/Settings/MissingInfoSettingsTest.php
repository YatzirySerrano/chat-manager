<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Configuración › Información faltante: the catalog of «Marcar que necesita
| información» and the first line of the message for the customer. Saved
| apart from the rest of the configuration (settings.update), audited.
*/

beforeEach(function () {
    config(['features.phase_two' => true]);
});

test('the catalog has sensible defaults and the settings page receives it', function () {
    expect(app(PlatformSettings::class)->get('missing_info.items'))->toContain('INE por ambos lados', 'Comprobante de domicilio', 'Estado de cuenta')
        ->and(app(PlatformSettings::class)->get('missing_info.intro'))->toBe('Para continuar con tu solicitud nos falta:');

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.missing_info.items.0', 'INE por ambos lados'));
});

test('administrators replace the catalog, trimmed, in their order, and audited', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->put(route('admin.settings.missing-info.update'), [
        'intro' => ' Para seguir necesitamos: ',
        'items' => ['  Estado de cuenta  ', 'Comprobante   de domicilio'],
    ])->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Información faltante guardada.']);

    $settings = app(PlatformSettings::class);

    // A shorter list than the defaults does not get the rest of them back.
    expect($settings->get('missing_info.items'))->toBe(['Estado de cuenta', 'Comprobante de domicilio'])
        ->and($settings->get('missing_info.intro'))->toBe('Para seguir necesitamos:')
        ->and(AuditLog::query()->where('action', AuditAction::SettingsUpdated)->count())->toBe(1);

    $this->actingAs($admin)->put(route('admin.settings.missing-info.update'), ['intro' => 'Nos falta:', 'items' => []])->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->get('missing_info.items'))->toBe([]);
});

test('the catalog is validated', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('admin.settings.missing-info.update'), [
            'intro' => '',
            'items' => ['CURP', 'curp', '', str_repeat('a', 81)],
        ])->assertSessionHasErrors(['intro', 'items.0', 'items.1', 'items.2', 'items.3']);
});

test('only users who can update settings change the catalog', function () {
    $this->actingAs(User::factory()->supervisor()->create())
        ->put(route('admin.settings.missing-info.update'), ['intro' => 'Nos falta:', 'items' => []])
        ->assertForbidden();
});

test('guests cannot change the catalog', function () {
    $this->put(route('admin.settings.missing-info.update'), ['intro' => 'Nos falta:', 'items' => []])->assertRedirect(route('login'));
});

test('the catalog does not exist while phase two is off', function () {
    config(['features.phase_two' => false]);

    $this->actingAs(User::factory()->administrator()->create())
        ->put(route('admin.settings.missing-info.update'), ['intro' => 'Nos falta:', 'items' => []])
        ->assertNotFound();
});
