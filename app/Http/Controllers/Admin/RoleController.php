<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\RestoreRole;
use App\Actions\Roles\RoleCatalogQuery;
use App\Actions\Roles\RoleGrantOverlap;
use App\Actions\Roles\SaveRole;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Services\PrivilegeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * The roles catalog (the same query as the roles report).
     */
    public function index(Request $request, PrivilegeGuard $guard, RoleCatalogQuery $catalog, ReportExporter $exporter, RoleGrantOverlap $overlap): Response
    {
        Gate::authorize('viewAny', Role::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $catalog->filters($actor, $request->validate(RoleCatalogQuery::rules()));
        $record = $filters['record'];

        $roles = $catalog->handle($actor, $filters)->get();
        $retained = $overlap->retainedElsewhere(array_values(array_map('intval', $roles->modelKeys())));
        $roles->each(fn (Role $role) => $role->setAttribute('retained_elsewhere', $retained[$role->id] ?? []));
        $isRoot = $actor->hasRootAuthority();

        return Inertia::render('admin/roles/Index', [
            'roles' => RoleResource::collection($roles)->resolve($request),
            'filters' => ['record' => $record->value],
            'recordStates' => RecordState::optionsFor($actor),
            // Permissions reserved to the root role are only listed for the
            // root authority: nobody else can hold them, nor should learn
            // about the account that does.
            'catalog' => $isRoot ? Permission::catalog() : $this->catalogWithoutReserved(),
            'scopes' => array_map(fn (array $scope): array => [
                ...$scope,
                'grantable' => $guard->canGrantScope($actor, DataScope::from($scope['value'])),
            ], DataScope::options()),
            // Reserved permissions are never granted to another role, so they
            // are not offered in the editor (the root role keeps its own).
            'grantablePermissions' => array_values(array_diff(
                $actor->hasRootAuthority()
                    ? array_column(Permission::available(), 'value')
                    : array_intersect($actor->effectivePermissions(), array_column(Permission::available(), 'value')),
                Permission::reservedToRootValues(),
            )),
            'rootCapabilities' => $isRoot ? array_column(Permission::rootCapabilities(), 'value') : [],
            'can' => [
                'create' => $actor->can('create', Role::class),
            ],
            'canExport' => $exporter->allowsReport('roles', $actor),
        ]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $result = $saveRole->save(null, $request->payload(), $request->user());

        Inertia::flash('toast', $result->toast());
        Inertia::flash('highlight', ['id' => $result->role->id]);

        return back();
    }

    public function update(RoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $result = $saveRole->save($role, $request->payload(), $request->user());

        // Says whom the change reaches: nobody yet, N active users, and those
        // who keep a revoked permission through another role or a direct grant.
        Inertia::flash('toast', $result->toast());

        if ($result->changed) {
            Inertia::flash('highlight', ['id' => $role->id]);
        }

        return back();
    }

    /**
     * Logical deletion: the role stops granting anything and keeps its definition.
     */
    public function destroy(Request $request, Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $deleteRole->handle($role, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rol {$role->label} eliminado."]);

        return back();
    }

    public function restore(Request $request, Role $role, RestoreRole $restoreRole): RedirectResponse
    {
        Gate::authorize('restore', $role);

        $restoreRole->handle($role, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rol {$role->label} restaurado."]);

        return back();
    }

    /**
     * The permission catalog without the permissions reserved to the root
     * role (and without groups left empty).
     *
     * @return list<array{value: string, label: string, description: string, icon: string, permissions: list<array<string, mixed>>}>
     */
    private function catalogWithoutReserved(): array
    {
        $groups = [];

        foreach (Permission::catalog() as $group) {
            $permissions = array_values(array_filter($group['permissions'], fn (array $permission): bool => ! $permission['reserved']));

            if ($permissions !== []) {
                $groups[] = [...$group, 'permissions' => $permissions];
            }
        }

        return $groups;
    }
}
