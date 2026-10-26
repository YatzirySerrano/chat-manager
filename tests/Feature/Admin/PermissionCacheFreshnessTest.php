<?php

use App\Actions\Roles\RoleGrantOverlap;
use App\Actions\Roles\SaveRole;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Events\Users\UserAccessChanged;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

/*
| Permission changes must be visible to whoever acts on them right away.
|
| A long-lived queue worker loads the permission collection once. A role
| change made by another process (a web request) only forgets the shared
| cache store, so each job must reload the collection from it: otherwise
| queued exports, sends and notifications keep honouring revoked grants
| until the worker restarts.
*/

test('every queued job sees role permissions revoked by another process', function () {
    $agent = User::factory()->agent()->create();
    $registrar = app(PermissionRegistrar::class);

    expect(User::query()->findOrFail($agent->id)->holdsPermission(Permission::TransferConversations))->toBeTrue();

    // Another process (a web request) revokes the permission from the role
    // and forgets the shared cache store; this worker's memory is untouched.
    $role = $agent->roles()->firstOrFail();
    $permissionId = DB::table('permissions')->where('name', Permission::TransferConversations->value)->value('id');
    DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permissionId)->delete();
    $registrar->getCacheStore()->forget($registrar->cacheKey);

    expect(User::query()->findOrFail($agent->id)->holdsPermission(Permission::TransferConversations))->toBeTrue();

    event(new JobProcessing('database', new SyncJob(app(), json_encode(['uuid' => 'job', 'displayName' => 'Job', 'job' => 'Job', 'data' => []]), 'database', 'default')));

    expect(User::query()->findOrFail($agent->id)->holdsPermission(Permission::TransferConversations))->toBeFalse();
});

test('holders are told about a role change only once the permission cache no longer has the old grants', function () {
    $root = User::factory()->superAdministrator()->create();
    $agent = User::factory()->agent()->create();
    $role = $agent->roles()->firstOrFail();
    $registrar = app(PermissionRegistrar::class);

    expect($agent->fresh()->holdsPermission(Permission::TransferConversations))->toBeTrue();

    // A concurrent request reloads the cache from the committed (old) grants
    // after the role sync forgot it but before the role change commits.
    $stale = $registrar->getCacheStore()->get($registrar->cacheKey);
    AuditLog::created(function () use ($registrar, $stale): void {
        $registrar->getCacheStore()->put($registrar->cacheKey, $stale, 3600);
        $registrar->clearPermissionsCollection();
    });

    $seenAtDispatch = null;
    Event::listen(UserAccessChanged::class, function () use (&$seenAtDispatch, $agent, $registrar): void {
        $seenAtDispatch = [
            'cached' => $registrar->getCacheStore()->get($registrar->cacheKey) !== null,
            'holds' => User::query()->findOrFail($agent->id)->holdsPermission(Permission::TransferConversations),
        ];
    });

    app(SaveRole::class)->save($role, [
        'label' => $role->label,
        'description' => $role->description,
        'scope' => $role->scope,
        'permissions' => array_values(array_diff($role->permissionNames(), [Permission::TransferConversations->value])),
    ], $root);

    expect($seenAtDispatch)->toBe(['cached' => false, 'holds' => false]);
});

test('the roles screen counts each holder once per permission kept through another source', function () {
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::UseQuickReplies])->create();
    $other = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewConversations, Permission::ViewContacts])->create();

    // Both a direct grant and another role: still one holder.
    $twice = User::factory()->withRole($role)->create();
    $twice->assignRole($other);
    $twice->givePermissionTo(Permission::ViewConversations->value);
    $viaRole = User::factory()->withRole($role)->create();
    $viaRole->assignRole($other);
    $inactive = User::factory()->withRole($role)->inactive()->create();
    $inactive->givePermissionTo(Permission::UseQuickReplies->value);

    // ViewContacts is not one of the role's own permissions, so it is not listed.
    expect(app(RoleGrantOverlap::class)->retainedElsewhere([$role->id, $other->id]))->toBe([
        $role->id => [Permission::ViewConversations->value => 2],
        $other->id => [Permission::ViewConversations->value => 2],
    ]);
});
