<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RestoreUser;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\UserListQuery;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Services\PrivilegeGuard;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Users within the actor's data scope, filtered, sorted and paginated.
     * Deleted accounts are only listed for people who can consult them.
     */
    public function index(Request $request, UserListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'departments:id']);

        $filters = $list->filters($actor, $request->validate(UserListQuery::rules()));
        $search = $filters['search'];
        $roleId = $filters['role'];
        $departmentId = $filters['department'];
        $record = $filters['record'];
        $sort = $filters['sort'];

        $users = $list->handle($actor, $filters)
            ->with([
                'roles.permissions',
                'departments' => fn (BelongsToMany $query) => $query
                    ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                    ->hidingDeletedFrom($actor)
                    ->orderBy('departments.name'),
            ])
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => AdminUserResource::collection($users),
            'filters' => [
                'search' => $search,
                'role' => $roleId,
                'department' => $departmentId,
                'record' => $record->value,
                'sort' => $sort,
            ],
            'recordStates' => RecordState::optionsFor($actor),
            'stats' => $this->stats($actor),
            'roles' => $this->roleOptions($actor),
            'departments' => Department::query()
                ->visibleTo($actor)
                ->notDeleted()
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'is_active'])
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'color' => $department->color->value,
                    'is_active' => $department->is_active,
                ]),
            'can' => [
                'create' => $actor->can('create', User::class),
            ],
            'canExport' => $exporter->allowsReport('users', $actor),
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $user = $createUser->handle($request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario {$user->name} creado."]);
        Inertia::flash('highlight', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

        return back();
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        $changed = $updateUser->handle($user, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Cambios guardados.');

        if ($changed) {
            Inertia::flash('highlight', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
        }

        return back();
    }

    /**
     * Logical deletion: the account loses access and keeps its history.
     */
    public function destroy(Request $request, User $user, DeleteUser $deleteUser): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $deleteUser->handle($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario {$user->name} eliminado. Su historial se conserva."]);

        return back();
    }

    public function restore(Request $request, User $user, RestoreUser $restoreUser): RedirectResponse
    {
        Gate::authorize('restore', $user);

        $restoreUser->handle($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario {$user->name} restaurado. Ya puede iniciar sesión."]);

        return back();
    }

    /**
     * Roles for filtering, flagging the ones the actor can assign (never a
     * deleted role, never the root role for a non-root actor). Deleted roles
     * are only listed for people who can consult them.
     *
     * @return list<array{id: int, label: string, description: string|null, scope: string, scope_label: string, is_active: bool, is_root: bool, assignable: bool, allows_departments: bool}>
     */
    private function roleOptions(User $actor): array
    {
        $guard = app(PrivilegeGuard::class);

        return array_values(Role::query()
            ->hidingDeletedFrom($actor)
            ->with('permissions')
            ->orderByDesc('is_root')
            ->orderBy('label')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'label' => $role->label,
                'description' => $role->description,
                'scope' => $role->scope->value,
                'scope_label' => $role->scope->label(),
                'is_active' => $role->is_active,
                'is_root' => $role->is_root,
                'assignable' => $role->is_active && $guard->canAssignRole($actor, $role),
                'allows_departments' => $role->scope->allowsDepartmentMembership(),
            ])
            ->all());
    }

    /**
     * Counters over the actor's scope. Deleted accounts are only counted for
     * people who can consult them (null otherwise).
     *
     * @return array{active: int, two_factor: int, deleted: int|null}
     */
    private function stats(User $actor): array
    {
        /** @var object{active: int|string|null, two_factor: int|string|null, deleted: int|string|null} $counts */
        $counts = User::query()->visibleTo($actor)->toBase()
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) AS active', [true])
            ->selectRaw('SUM(CASE WHEN is_active = ? AND two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS two_factor', [true])
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 0 ELSE 1 END) AS deleted', [true])
            ->first();

        return [
            'active' => (int) $counts->active,
            'two_factor' => (int) $counts->two_factor,
            'deleted' => $actor->canViewDeletedRecords() ? (int) $counts->deleted : null,
        ];
    }
}
