<?php

use App\Enums\AuditAction;
use App\Enums\DefaultRole;
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
    $admin = User::factory()->administrator()->create(['name' => 'Admin Principal']);
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
