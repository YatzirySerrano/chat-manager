<?php

use App\Actions\Departments\AddDepartmentMember;
use App\Actions\Departments\RemoveDepartmentMember;
use App\Actions\Departments\UpdateDepartmentDistribution;
use App\Actions\Roles\SaveRole;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RestoreUser;
use App\Actions\Users\UpdateUser;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Events\Broadcasts\RealtimeSignal;
use App\Events\Users\UserAccessChanged;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['broadcasting.default' => 'log']);
    $this->admin = User::factory()->superAdministrator()->create();
});

test('changing the departments of a user tells their open sessions to revalidate access', function () {
    $sales = Department::factory()->create();
    $support = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($sales)->create();
    Event::fake([RealtimeSignal::class]);

    app(UpdateUser::class)->handle($agent, [
        'name' => $agent->name,
        'email' => $agent->email,
        'role' => null,
        'department_ids' => [$support->id],
    ], $this->admin);

    Event::assertDispatched(RealtimeSignal::class, fn (RealtimeSignal $signal): bool => $signal->event === 'access.changed'
        && $signal->channels === ["users.{$agent->id}"]
        && $signal->payload === ['reason' => 'departments_changed']);
});

test('editing only the profile does not revalidate access', function () {
    $agent = User::factory()->agent()->create();
    Event::fake([UserAccessChanged::class]);

    app(UpdateUser::class)->handle($agent, [
        'name' => 'Nombre corregido',
        'email' => $agent->email,
        'role' => null,
        'department_ids' => null,
    ], $this->admin);

    Event::assertNotDispatched(UserAccessChanged::class);
});

test('deleting and restoring an account revalidate its sessions', function () {
    $agent = User::factory()->agent()->create();
    Event::fake([UserAccessChanged::class]);

    app(DeleteUser::class)->handle($agent, $this->admin);
    app(RestoreUser::class)->handle($agent->fresh(), $this->admin);

    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id] && $event->reason === 'deleted');
    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id] && $event->reason === 'restored');
});

test('changing the permissions of a role revalidates every holder and nobody else', function () {
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations])->create();
    $holders = User::factory()->withRole($role)->count(2)->create();
    $unrelated = User::factory()->agent()->create();
    Event::fake([RealtimeSignal::class]);

    app(SaveRole::class)->update($role, [
        'label' => $role->label,
        'description' => $role->description,
        'scope' => DataScope::Assigned,
        'permissions' => [Permission::ViewConversations->value, Permission::ReplyConversations->value],
    ], $this->admin);

    $channels = Event::dispatched(RealtimeSignal::class)
        ->map(fn (array $arguments): RealtimeSignal => $arguments[0])
        ->where('event', 'access.changed')
        ->flatMap(fn (RealtimeSignal $signal): array => $signal->channels)
        ->all();

    expect($channels)->toEqualCanonicalizing($holders->map(fn (User $user): string => "users.{$user->id}")->all())
        ->not->toContain("users.{$unrelated->id}");
});

test('adding and removing department members revalidates the member', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->create();
    Event::fake([UserAccessChanged::class]);

    app(AddDepartmentMember::class)->handle($department, $agent, $this->admin);
    app(RemoveDepartmentMember::class)->handle($department, $agent->fresh(), $this->admin);

    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id] && $event->reason === 'department_member_added');
    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id] && $event->reason === 'department_member_removed');
});

test('switching a department to or from manual distribution revalidates its members', function () {
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    Event::fake([UserAccessChanged::class]);

    app(UpdateDepartmentDistribution::class)->handle($department, DistributionMode::Automatic, $this->admin);
    Event::assertNotDispatched(UserAccessChanged::class);

    app(UpdateDepartmentDistribution::class)->handle($department, DistributionMode::Manual, $this->admin);
    Event::assertDispatched(UserAccessChanged::class, fn (UserAccessChanged $event): bool => $event->userIds === [$agent->id]);
});
