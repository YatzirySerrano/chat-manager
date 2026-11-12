<?php

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
| User::checkPermissionTo() answers from an index (PermissionMemo) instead of
| the package's per-check lookup, because a page asks it hundreds of times.
| It must give exactly the package's answer, and never outlive a change the
| package itself would already see.
*/

/** The package's own check, without the index. */
function packageCheck(User $user, string $name): bool
{
    return Closure::bind(fn (): bool => $this->uncachedCheckPermissionTo($name), $user, User::class)();
}

test('the indexed check gives the package answer for every permission and role', function () {
    $direct = User::factory()->agent()->create();
    $direct->givePermissionTo(Permission::CreatePersonalQuickReplies->value);

    $users = [
        User::factory()->superAdministrator()->create(),
        User::factory()->administrator()->create(),
        User::factory()->supervisor()->create(),
        User::factory()->agent()->create(),
        User::factory()->create(),
        $direct,
    ];

    $names = [...Permission::values(), 'view', 'update', 'no.such.permission', ''];

    foreach ($users as $user) {
        $fresh = User::query()->findOrFail($user->id);

        foreach ($names as $name) {
            expect($fresh->checkPermissionTo($name))->toBe(packageCheck($fresh, $name), "{$user->email}: {$name}");
            // Asked again: the remembered answer.
            expect($fresh->checkPermissionTo($name))->toBe(packageCheck($fresh, $name));
        }
    }
});

test('a permission granted or revoked on the role is seen at once by the same user instance', function () {
    $agent = User::factory()->agent()->create();
    $role = $agent->roles()->firstOrFail();

    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeTrue();

    $role->revokePermissionTo(Permission::TransferConversations->value);

    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeFalse()
        ->and($agent->holdsPermission(Permission::TransferConversations))->toBeFalse();

    $role->givePermissionTo(Permission::TransferConversations->value);

    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeTrue();
});

test('a direct permission and a role change are seen at once by the same user instance', function () {
    $agent = User::factory()->agent()->create();
    $name = Permission::CreatePersonalQuickReplies->value;
    $before = $agent->checkPermissionTo($name);

    $agent->givePermissionTo($name);
    expect($agent->checkPermissionTo($name))->toBeTrue();

    $agent->revokePermissionTo($name);
    expect($agent->checkPermissionTo($name))->toBe($before);

    expect($agent->checkPermissionTo(Permission::UpdateRoles->value))->toBeFalse();

    $agent->syncRoles([User::factory()->superAdministrator()->create()->roles()->firstOrFail()]);

    expect($agent->checkPermissionTo(Permission::UpdateRoles->value))->toBeTrue()
        ->and($agent->hasRootAuthority())->toBeTrue();
});

test('the scope and root authority follow the stored values of the user and the role', function () {
    $admin = User::factory()->superAdministrator()->create();
    $role = $admin->roles->firstOrFail();

    expect($admin->hasRootAuthority())->toBeTrue()
        ->and($admin->dataScope())->not->toBeNull();

    $admin->is_active = false;
    expect($admin->hasRootAuthority())->toBeFalse()
        ->and($admin->dataScope())->toBeNull();

    $admin->is_active = true;
    expect($admin->hasRootAuthority())->toBeTrue();

    $role->is_active = false;
    expect($admin->hasRootAuthority())->toBeFalse()
        ->and($admin->dataScope())->toBeNull()
        ->and($admin->holdsPermission(Permission::UpdateRoles))->toBeFalse();
});

test('dropping the registrar collection starts a new index', function () {
    $agent = User::factory()->agent()->create();
    $registrar = app(PermissionRegistrar::class);

    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeTrue();

    $permissionId = DB::table('permissions')->where('name', Permission::TransferConversations->value)->value('id');
    DB::table('role_has_permissions')->where('role_id', $agent->roles->firstOrFail()->id)->where('permission_id', $permissionId)->delete();

    // Still the collection in memory, exactly like the package.
    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeTrue();

    $registrar->forgetCachedPermissions();

    expect($agent->checkPermissionTo(Permission::TransferConversations->value))->toBeFalse();
});

test('repeated checks on a loaded user cost no queries', function () {
    $agent = User::query()->findOrFail(User::factory()->agent()->create()->id);
    $agent->checkPermissionTo(Permission::ViewContacts->value);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach (Permission::values() as $name) {
        $agent->checkPermissionTo($name);
        $agent->can('view', $agent);
    }

    expect($queries)->toBe(0);
});
