<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;

test('reports whether an email is free with a single boolean', function () {
    $admin = User::factory()->superAdministrator()->create();
    User::factory()->create(['email' => 'laura@empresa.test']);

    $this->actingAs($admin)
        ->postJson(route('admin.users.email-availability'), ['email' => ' LAURA@empresa.test '])
        ->assertOk()
        ->assertExactJson(['available' => false]);

    $this->actingAs($admin)
        ->postJson(route('admin.users.email-availability'), ['email' => 'nueva@empresa.test'])
        ->assertOk()
        ->assertExactJson(['available' => true]);
});

test('the edited user does not conflict with their own email', function () {
    $admin = User::factory()->superAdministrator()->create();
    $user = User::factory()->agent()->create(['email' => 'laura@empresa.test']);

    $this->actingAs($admin)
        ->postJson(route('admin.users.email-availability'), ['email' => 'laura@empresa.test', 'user_id' => $user->id])
        ->assertExactJson(['available' => true]);
});

test('forbids the check to people who cannot create or update users', function () {
    $agent = User::factory()->agent()->create();
    User::factory()->create(['email' => 'laura@empresa.test']);

    $this->actingAs($agent)
        ->postJson(route('admin.users.email-availability'), ['email' => 'laura@empresa.test'])
        ->assertForbidden();
});

test('an account out of reach and a missing one answer the same', function () {
    $department = Department::factory()->create();
    $role = Role::factory()->scope(DataScope::Departments)->withPermissions([Permission::ViewUsers, Permission::UpdateUsers])->create();
    $manager = User::factory()->withRole($role)->inDepartments($department)->create();
    $administrator = User::factory()->superAdministrator()->create();

    $this->actingAs($manager)
        ->postJson(route('admin.users.email-availability'), ['email' => 'otra@empresa.test', 'user_id' => $administrator->id])
        ->assertNotFound();

    $this->actingAs($manager)
        ->postJson(route('admin.users.email-availability'), ['email' => 'otra@empresa.test', 'user_id' => User::query()->max('id') + 1000])
        ->assertNotFound();
});

test('throttles repeated checks', function () {
    $admin = User::factory()->superAdministrator()->create();

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($admin)->postJson(route('admin.users.email-availability'), ['email' => "persona{$attempt}@empresa.test"])->assertOk();
    }

    $this->actingAs($admin)
        ->postJson(route('admin.users.email-availability'), ['email' => 'otra@empresa.test'])
        ->assertTooManyRequests();
});
