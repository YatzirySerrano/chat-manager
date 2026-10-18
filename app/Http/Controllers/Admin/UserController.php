<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Sort options exposed in the interface.
     *
     * @var array<string, array{0: string, 1: 'asc'|'desc'}>
     */
    private const SORTS = [
        'name' => ['name', 'asc'],
        'newest' => ['created_at', 'desc'],
        'last_login' => ['last_login_at', 'desc'],
    ];

    /**
     * Users within the actor's data scope, filtered, sorted and paginated.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $actor->loadMissing(['roles.permissions', 'departments:id']);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $roleId = isset($filters['role']) ? (int) $filters['role'] : null;
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        $status = $filters['status'] ?? null;
        $sort = $filters['sort'] ?? 'name';
        [$sortColumn, $sortDirection] = self::SORTS[$sort];

        $users = User::query()
            ->visibleTo($actor)
            ->with([
                'roles.permissions',
                'departments' => fn (BelongsToMany $query) => $query
                    ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                    ->orderBy('departments.name'),
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($roleId !== null, fn (Builder $query) => $query->withRole($roleId))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($departmentId),
            ))
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => AdminUserResource::collection($users),
            'filters' => [
                'search' => $search,
                'role' => $roleId,
                'department' => $departmentId,
                'status' => $status,
                'sort' => $sort,
            ],
            'stats' => $this->stats($actor),
            'roles' => $this->roleOptions($actor),
            'departments' => Department::query()
                ->visibleTo($actor)
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
        $updateUser->handle($user, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cambios guardados.']);
        Inertia::flash('highlight', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);

        return back();
    }

    public function destroy(Request $request, User $user, DeleteUser $deleteUser): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $deleteUser->handle($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Usuario {$user->name} eliminado."]);

        return back();
    }

    /**
     * Every role for filtering, flagging the ones the actor can assign.
     *
     * @return list<array{id: int, label: string, description: string|null, scope: string, scope_label: string, is_active: bool, is_root: bool, assignable: bool, allows_departments: bool}>
     */
    private function roleOptions(User $actor): array
    {
        $guard = app(PrivilegeGuard::class);

        return array_values(Role::query()
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
     * @return array{total: int, active: int, inactive: int}
     */
    private function stats(User $actor): array
    {
        /** @var object{total: int|string|null, active: int|string|null} $counts */
        $counts = User::query()->visibleTo($actor)->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN is_active = ? THEN 1 ELSE 0 END) AS active', [true])
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->total - (int) $counts->active,
        ];
    }
}
