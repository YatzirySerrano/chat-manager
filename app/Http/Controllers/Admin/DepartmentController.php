<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\CreateDepartment;
use App\Actions\Departments\UpdateDepartment;
use App\Enums\ColorSwatch;
use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Resources\DepartmentMemberResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Global roles see every department; other roles only the ones they belong to.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Department::class);

        $user = $request->user();

        $departments = Department::query()
            ->visibleTo($user)
            ->withCount(['members', 'leaders'])
            ->with(['leaders' => fn (BelongsToMany $query) => $query
                ->select('users.id', 'users.name')
                ->orderBy('users.name')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('departments/Index', [
            'departments' => DepartmentResource::collection($departments)->resolve($request),
            'colors' => ColorSwatch::values(),
            'can' => [
                'create' => $user->can('create', Department::class),
            ],
        ]);
    }

    public function store(DepartmentRequest $request, CreateDepartment $createDepartment): RedirectResponse
    {
        $department = $createDepartment->handle($request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departamento {$department->name} creado."]);

        return to_route('departments.show', $department);
    }

    /**
     * Department detail with its members and the users that can be added.
     */
    public function show(Request $request, Department $department): Response
    {
        Gate::authorize('view', $department);

        $actor = $request->user();

        $members = $department->members()
            ->with(['roles.permissions', 'departments:id'])
            ->orderBy('users.name')
            ->get();

        $canManageMembers = $actor->can('manageMembers', $department);

        return Inertia::render('departments/Show', [
            'department' => DepartmentResource::make($department->loadCount(['members', 'leaders']))->resolve($request),
            'members' => DepartmentMemberResource::collection($members)->resolve($request),
            'removableMemberIds' => $members
                ->filter(fn (User $member): bool => $actor->can('manageMember', [$department, $member]))
                ->pluck('id')
                ->values(),
            'candidates' => $canManageMembers ? $this->candidates($department, $actor) : [],
            'colors' => ColorSwatch::values(),
            'can' => [
                'update' => $actor->can('update', $department),
                'changeStatus' => $actor->can('changeStatus', $department),
                'manageMembers' => $canManageMembers,
            ],
        ]);
    }

    public function update(DepartmentRequest $request, Department $department, UpdateDepartment $updateDepartment): RedirectResponse
    {
        $updateDepartment->handle($department, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Departamento actualizado.']);

        return back();
    }

    /**
     * Active users with an active role that allows department membership and
     * that the actor is allowed to manage.
     *
     * @return list<array{id: int, name: string, email: string, role: array{id: int, label: string}}>
     */
    private function candidates(Department $department, User $actor): array
    {
        return array_values(User::query()
            ->active()
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where('is_active', true)
                ->where('scope', '!=', DataScope::Global->value))
            ->whereDoesntHave('departments', fn (Builder $departments) => $departments->whereKey($department->id))
            ->with(['roles.permissions', 'departments:id'])
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user): bool => $actor->can('manageMember', [$department, $user]))
            ->map(function (User $user): array {
                /** @var Role $role */
                $role = $user->role();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => ['id' => $role->id, 'label' => $role->label],
                ];
            })
            ->all());
    }
}
