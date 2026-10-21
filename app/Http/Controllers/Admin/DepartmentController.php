<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\CreateDepartment;
use App\Actions\Departments\SummarizeDepartmentConversations;
use App\Actions\Departments\UpdateDepartment;
use App\Enums\ColorSwatch;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Resources\DepartmentMemberResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Connection;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
            ->withCount(['members', 'leaders', 'connections'])
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
     * Department detail with its members, the users that can be added and its
     * operational configuration (welcome message, hours, connections, quick
     * replies and real conversation counts).
     */
    public function show(Request $request, Department $department, SummarizeDepartmentConversations $summarizeConversations, PlatformSettings $settings): Response
    {
        Gate::authorize('view', $department);

        $actor = $request->user();

        $members = $department->members()
            ->with(['roles.permissions', 'departments:id'])
            ->orderBy('users.name')
            ->get();

        $canManageMembers = $actor->can('manageMembers', $department);

        return Inertia::render('departments/Show', [
            'department' => DepartmentResource::make($department->loadCount(['members', 'leaders', 'connections']))->resolve($request),
            'members' => DepartmentMemberResource::collection($members)->resolve($request),
            'removableMemberIds' => $members
                ->filter(fn (User $member): bool => $actor->can('manageMember', [$department, $member]))
                ->pluck('id')
                ->values(),
            'candidates' => $canManageMembers ? $this->candidates($department, $actor) : [],
            'colors' => ColorSwatch::values(),
            'metrics' => $summarizeConversations->handle($department),
            'platformHours' => $settings->get('business_hours'),
            'weekdays' => PlatformSettings::WEEKDAYS,
            'connections' => $this->connections($department, $actor),
            'quickReplies' => $this->quickReplies($department, $actor),
            'links' => [
                'connections' => Route::has('connections.index') && $actor->holdsPermission(Permission::ViewConnections)
                    ? route('connections.index')
                    : null,
                'quickReplies' => Route::has('quick-replies.index') && $actor->holdsPermission(Permission::ManageQuickReplies)
                    ? route('quick-replies.index')
                    : null,
            ],
            'distributionModes' => DistributionMode::options(),
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
     * Connections authorized to route conversations to the department. The
     * association is edited from the connection form; only users who can
     * view connections receive a link to it. No credential is exposed.
     *
     * @return list<array{id: int, name: string, display_phone_number: string|null, is_active: bool, is_default: bool, health: array{value: string, label: string, tone: string}, url: string|null}>
     */
    private function connections(Department $department, User $actor): array
    {
        $canOpen = Route::has('connections.show') && $actor->holdsPermission(Permission::ViewConnections);

        return array_values($department->connections()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(function (Connection $connection) use ($department, $canOpen): array {
                $health = $connection->health();

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'display_phone_number' => $connection->display_phone_number,
                    'is_active' => $connection->is_active,
                    'is_default' => $connection->default_department_id === $department->id,
                    'health' => ['value' => $health->value, 'label' => $health->label(), 'tone' => $health->tone()],
                    'url' => $canOpen ? route('connections.show', $connection) : null,
                ];
            })
            ->all());
    }

    /**
     * Quick replies shared specifically with the department plus the number of
     * active global replies, or null when the actor cannot use quick replies.
     *
     * @return array{items: list<array{id: int, title: string, shortcut: string, is_active: bool}>, global_count: int}|null
     */
    private function quickReplies(Department $department, User $actor): ?array
    {
        if (! $actor->holdsPermission(Permission::UseQuickReplies) && ! $actor->holdsPermission(Permission::ManageQuickReplies)) {
            return null;
        }

        return [
            'items' => array_values($department->quickReplies()
                ->where('is_global', false)
                ->orderByDesc('is_active')
                ->orderBy('title')
                ->get(['quick_replies.id', 'title', 'shortcut', 'is_active'])
                ->map(fn (QuickReply $reply): array => [
                    'id' => $reply->id,
                    'title' => $reply->title,
                    'shortcut' => $reply->shortcut,
                    'is_active' => $reply->is_active,
                ])
                ->all()),
            'global_count' => QuickReply::query()->active()->where('is_global', true)->count(),
        ];
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
