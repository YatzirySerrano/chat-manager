<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Inertia\Testing\AssertableInertia as Assert;

test('only administrators can view the audit log', function (DefaultRole $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
})->with([
    'supervisor' => DefaultRole::Supervisor,
    'agent' => DefaultRole::Agent,
]);

test('administrators can browse and filter audit events', function () {
    $admin = User::factory()->superAdministrator()->create(['name' => 'Admin Principal']);
    $logger = app(AuditLogger::class);
    $department = Department::factory()->create(['name' => 'Ventas']);
    $logger->record(AuditAction::DepartmentCreated, $department, [], $admin);
    $logger->record(AuditAction::SettingsUpdated, null, [], $admin);

    $this->actingAs($admin)
        ->get(route('admin.audit.index', ['action' => AuditAction::DepartmentCreated->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/audit/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action.value', 'department.created')
            ->where('logs.data.0.actor.name', 'Admin Principal')
            ->where('logs.data.0.subject.type', 'department')
            ->where('logs.data.0.subject.label', 'Ventas')
        );
});

test('sensitive values are never written to the audit log', function () {
    $log = app(AuditLogger::class)->record(AuditAction::UserUpdated, null, [
        'password' => 'secret-1',
        'nested' => ['access_token' => 'secret-2', 'app_secret' => 'secret-3', 'visible' => 'ok'],
        'remember_token' => 'secret-4',
    ]);

    expect($log->refresh()->properties)->toBe(['nested' => ['visible' => 'ok']]);
});

test('entries about the root authority are restricted when they are written', function () {
    $root = User::factory()->superAdministrator()->create();
    $administrator = User::factory()->administrator()->create();
    $agent = User::factory()->agent()->create();
    $logger = app(AuditLogger::class);

    expect($logger->record(AuditAction::SettingsUpdated, null, [], $root)->restricted)->toBeTrue()
        ->and($logger->record(AuditAction::UserUpdated, $root, [], $administrator)->restricted)->toBeTrue()
        ->and($logger->record(AuditAction::RoleUpdated, role(DefaultRole::SuperAdministrator), [], null)->restricted)->toBeTrue()
        ->and($logger->record(AuditAction::UserUpdated, $agent, [], $administrator)->restricted)->toBeFalse()
        ->and($logger->record(AuditAction::RoleUpdated, role(DefaultRole::Agent), [], $administrator)->restricted)->toBeFalse();
});

test('an administrator never sees restricted entries, in the list or in the filter options; a superadministrator sees everything', function () {
    $root = User::factory()->superAdministrator()->create(['name' => 'Raíz']);
    $administrator = User::factory()->administrator()->create(['name' => 'Operación']);
    $logger = app(AuditLogger::class);
    $logger->record(AuditAction::SettingsUpdated, null, [], $root);
    $logger->record(AuditAction::DepartmentCreated, Department::factory()->create(), [], $administrator);

    $this->actingAs($administrator)
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.actor.name', 'Operación')
            ->where('actors', [['id' => $administrator->id, 'name' => 'Operación']]));

    $this->actingAs($administrator)
        ->get(route('admin.audit.index', ['actor' => $root->id]))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));

    $this->actingAs($root)
        ->get(route('admin.audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 2)
            ->has('actors', 2));
});

test('audit entries are append-only', function () {
    $entry = app(AuditLogger::class)->record(AuditAction::SettingsUpdated);

    expect(fn () => $entry->update(['actor_name' => 'Otro']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditLog::query()->find($entry->id))->actor_name->toBe('Sistema');
});
