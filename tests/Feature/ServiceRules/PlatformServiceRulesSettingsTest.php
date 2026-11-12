<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\ServiceRules;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The settings form as it was before the service rules existed: none of the
 * new groups or keys.
 *
 * @return array<string, mixed>
 */
function legacySettingsForm(array $overrides = []): array
{
    $defaults = app(PlatformSettings::class)->defaults();

    return array_replace_recursive([
        'general' => $defaults['general'],
        'business_hours' => $defaults['business_hours'],
        'operations' => $defaults['operations'],
        'sla' => ['first_response_minutes' => null, 'resolution_minutes' => null],
    ], $overrides);
}

beforeEach(function () {
    // Phase-two behavior (FEATURE_PHASE_TWO): docs/reglas-de-servicio.md#fase-dos-detrás-de-feature_phase_two.
    config(['features.phase_two' => true]);
});

test('the platform rules and the automatic alerts are saved and audited', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), legacySettingsForm([
            'sla' => ['first_response_minutes' => 15, 'response_minutes' => 30, 'resolution_minutes' => 525600, 'warning_percent' => 70, 'business_hours_only' => false],
            'intake' => ['confirmation_required' => true],
            'queue' => ['min_wait_minutes' => 3, 'chronological_order' => true],
            'sla_alerts' => ['agent_warning' => false, 'agent_breach' => true, 'supervisor_warning' => true, 'supervisor_breach' => true, 'reminder_minutes' => 30, 'reminder_limit' => 5],
        ]))
        ->assertSessionHasNoErrors();

    $rules = app(ServiceRules::class);
    $resolved = $rules->resolve(null, null);

    expect($resolved->firstResponseSeconds())->toBe(900)
        ->and($resolved->responseSeconds())->toBe(1800)
        ->and($resolved->resolutionSeconds())->toBe(525600 * 60)
        ->and($resolved->warningPercent())->toBe(70)
        ->and($resolved->businessHoursOnly())->toBeFalse()
        ->and($resolved->confirmationRequired())->toBeTrue()
        ->and($resolved->minWaitSeconds())->toBe(180)
        ->and($resolved->chronologicalOrder())->toBeTrue()
        ->and($rules->alerts())->toBe([
            'agent_warning' => false,
            'agent_breach' => true,
            'supervisor_warning' => true,
            'supervisor_breach' => true,
            'reminder_minutes' => 30,
            'reminder_limit' => 5,
        ]);

    $changes = AuditLog::query()->where('action', AuditAction::SettingsUpdated)->sole()->properties['changes'];

    expect($changes['sla.warning_percent'])->toBe(['old' => 80, 'new' => 70])
        ->and($changes['intake.confirmation_required'])->toBe(['old' => false, 'new' => true])
        ->and($changes['queue.min_wait_minutes'])->toBe(['old' => 0, 'new' => 3])
        ->and($changes['sla_alerts.reminder_minutes'])->toBe(['old' => null, 'new' => 30])
        ->and($changes)->not->toHaveKey('sla_alerts.agent_breach');
});

test('a form that does not know the new rules keeps them', function () {
    $admin = User::factory()->superAdministrator()->create();
    app(PlatformSettings::class)->update([
        'sla' => ['response_minutes' => 30, 'warning_percent' => 60],
        'queue' => ['min_wait_minutes' => 4],
        'sla_alerts' => ['reminder_minutes' => 20],
    ]);

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), legacySettingsForm(['sla' => ['first_response_minutes' => 10]]))
        ->assertSessionHasNoErrors();

    $settings = app(PlatformSettings::class)->all();

    expect($settings['sla'])->toBe([
        'first_response_minutes' => 10,
        'resolution_minutes' => null,
        'response_minutes' => 30,
        'warning_percent' => 60,
        'business_hours_only' => true,
    ])
        ->and($settings['queue']['min_wait_minutes'])->toBe(4)
        ->and($settings['sla_alerts']['reminder_minutes'])->toBe(20);
});

test('emptying an objective removes it and reminders can be turned off', function () {
    $admin = User::factory()->superAdministrator()->create();
    app(PlatformSettings::class)->update(['sla' => ['response_minutes' => 30], 'sla_alerts' => ['reminder_minutes' => 20]]);

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), legacySettingsForm([
            'sla' => ['response_minutes' => ''],
            'sla_alerts' => ['reminder_minutes' => null],
        ]))
        ->assertSessionHasNoErrors();

    expect(app(PlatformSettings::class)->get('sla.response_minutes'))->toBeNull()
        ->and(app(ServiceRules::class)->alerts()['reminder_minutes'])->toBeNull();
});

test('the platform rules are validated against their bounds', function (string $key, mixed $value) {
    $admin = User::factory()->superAdministrator()->create();
    [$group, $name] = explode('.', $key, 2);

    $this->actingAs($admin)
        ->put(route('admin.settings.update'), legacySettingsForm([$group => [$name => $value]]))
        ->assertSessionHasErrors($key);
})->with([
    'response objective below the minimum' => ['sla.response_minutes', 0],
    'response objective above the maximum' => ['sla.response_minutes', 43201],
    'resolution objective above the maximum' => ['sla.resolution_minutes', 525601],
    'warning below the minimum' => ['sla.warning_percent', 49],
    'warning above the maximum' => ['sla.warning_percent', 96],
    'warning is required when sent' => ['sla.warning_percent', ''],
    'business hours flag is not a boolean' => ['sla.business_hours_only', 'a veces'],
    'confirmation flag is not a boolean' => ['intake.confirmation_required', 'quizá'],
    'negative wait' => ['queue.min_wait_minutes', -1],
    'wait above the maximum' => ['queue.min_wait_minutes', 1441],
    'reminder too frequent' => ['sla_alerts.reminder_minutes', 4],
    'reminder too sparse' => ['sla_alerts.reminder_minutes', 1441],
    'reminder limit below the minimum' => ['sla_alerts.reminder_limit', 0],
    'reminder limit above the maximum' => ['sla_alerts.reminder_limit', 11],
]);

test('the settings page describes the platform rules', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.sla.warning_percent', 80)
            ->where('settings.intake.confirmation_required', false)
            ->where('settings.queue.chronological_order', false)
            ->where('settings.sla_alerts.reminder_limit', 3)
            ->where('serviceRules', function ($rules): bool {
                $rules = collect($rules)->toArray();

                return array_keys($rules) === array_keys(ServiceRules::KEYS)
                    && $rules['sla.warning_percent']['value'] === 80
                    && $rules['sla.warning_percent']['source'] === 'platform'
                    && $rules['sla.warning_percent']['inherited_source'] === null;
            })
        );
});
