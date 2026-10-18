<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\SaveRole;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request, PrivilegeGuard $guard): Response
    {
        Gate::authorize('viewAny', Role::class);

        /** @var User $actor */
        $actor = $request->user();

        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_root')
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->get();

        return Inertia::render('admin/roles/Index', [
            'roles' => RoleResource::collection($roles)->resolve($request),
            'catalog' => Permission::catalog(),
            'scopes' => array_map(fn (array $scope): array => [
                ...$scope,
                'grantable' => $guard->canGrantScope($actor, DataScope::from($scope['value'])),
            ], DataScope::options()),
            'grantablePermissions' => $actor->hasRootAuthority()
                ? array_column(Permission::available(), 'value')
                : array_values(array_intersect($actor->effectivePermissions(), array_column(Permission::available(), 'value'))),
            'rootCapabilities' => array_column(Permission::rootCapabilities(), 'value'),
            'can' => [
                'create' => $actor->can('create', Role::class),
            ],
        ]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $role = $saveRole->handle(null, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rol {$role->label} creado."]);
        Inertia::flash('highlight', ['id' => $role->id]);

        return back();
    }

    public function update(RoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle($role, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rol {$role->label} actualizado."]);
        Inertia::flash('highlight', ['id' => $role->id]);

        return back();
    }

    public function destroy(Request $request, Role $role, DeleteRole $deleteRole): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $deleteRole->handle($role, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rol {$role->label} eliminado."]);

        return back();
    }
}
