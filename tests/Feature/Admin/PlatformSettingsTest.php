<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array<string, mixed>
 */
function validSettingsPayload(array $overrides = []): array
{
    return array_replace_recursive(app(PlatformSettings::class)->defaults(), $overrides);
}

test('only administrators can manage the platform settings', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.settings.update'), validSettingsPayload())->assertForbidden();
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('administrators can view the platform settings', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/Edit')
            ->where('settings.general.timezone', 'America/Mexico_City')
            ->where('integration.active_whatsapp_accounts', 0)
        );
});

test('administrators can update the platform settings and the change is audited', function () {
    $admin = User::factory()->superAdministrator()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), validSettingsPayload([
            'general' => ['platform_name' => 'Atención Clientes', 'timezone' => 'America/Bogota'],
            'business_hours' => ['enabled' => true],
            'operations' => ['default_department_id' => $department->id, 'max_open_conversations_per_agent' => 8],
        ]))
        ->assertSessionHasNoErrors();

    $settings = app(PlatformSettings::class)->all();

    expect($settings['general']['platform_name'])->toBe('Atención Clientes')
        ->and($settings['general']['timezone'])->toBe('America/Bogota')
        ->and($settings['operations']['default_department_id'])->toBe($department->id);

    $changes = AuditLog::query()->where('action', AuditAction::SettingsUpdated)->sole()->properties['changes'];

    expect($changes)->toHaveKeys(['general.platform_name', 'general.timezone', 'business_hours.enabled']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('platform.name', 'Atención Clientes')
        );
});

test('platform settings are validated', function () {
    $admin = User::factory()->superAdministrator()->create();
    $inactive = Department::factory()->inactive()->create();

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), validSettingsPayload([
            'general' => ['platform_name' => '', 'timezone' => 'Mars/Olympus'],
            'business_hours' => ['days' => ['monday' => ['open' => true, 'start' => '18:00', 'end' => '09:00']]],
            'operations' => ['default_department_id' => $inactive->id],
        ]))
        ->assertSessionHasErrors([
            'general.platform_name',
            'general.timezone',
            'business_hours.days.monday.end' => 'La hora de cierre debe ser posterior a la hora de apertura.',
            'operations.default_department_id',
        ]);
});

test('saving the current settings writes nothing and says so', function () {
    $admin = User::factory()->superAdministrator()->create();
    $this->actingAs($admin)->put(route('admin.settings.update'), validSettingsPayload(['general' => ['platform_name' => 'Atención']]));
    AuditLog::query()->delete();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('admin.settings.update'), validSettingsPayload(['general' => ['platform_name' => '  Atención ']]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No había cambios por guardar.']));

    expect($writes)->toBe([])
        ->and(AuditLog::query()->exists())->toBeFalse();
});

test('saving the default settings for the first time does not create records', function () {
    $admin = User::factory()->superAdministrator()->create();

    $writes = databaseWritesDuring(fn () => $this->actingAs($admin)
        ->put(route('admin.settings.update'), validSettingsPayload())
        ->assertInertiaFlash('toast.type', 'info'));

    expect($writes)->toBe([]);
});
