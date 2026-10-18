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
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/Edit')
            ->where('settings.general.timezone', 'America/Mexico_City')
            ->where('integration.active_whatsapp_accounts', 0)
        );
});

test('administrators can update the platform settings and the change is audited', function () {
    $admin = User::factory()->administrator()->create();
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
    $admin = User::factory()->administrator()->create();
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
