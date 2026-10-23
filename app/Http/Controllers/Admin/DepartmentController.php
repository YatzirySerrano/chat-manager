<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\CreateDepartment;
use App\Actions\Departments\DeleteDepartment;
use App\Actions\Departments\DepartmentListQuery;
use App\Actions\Departments\RestoreDepartment;
use App\Actions\Departments\SummarizeDepartmentConversations;
use App\Actions\Departments\UpdateDepartment;
use App\Enums\ColorSwatch;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DepartmentRequest;
use App\Http\Resources\DepartmentMemberResource;
use App\Http\Resources\DepartmentResource;
use App\Models\Connection;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessHours;
use App\Services\Conversations\ConversationAccess;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Global roles see every department; other roles only the ones they belong
     * to. Deleted departments are only listed for people who can consult them.
     */
    public function index(Request $request, DepartmentListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', Department::class);

        /** @var User $user */
        $user = $request->user();

        $filters = $list->filters($user, $request->validate(DepartmentListQuery::rules()));
        $record = $filters['record'];

        $departments = $list->handle($user, $filters)->get();

        return Inertia::render('departments/Index', [
            'departments' => DepartmentResource::collection($departments)->resolve($request),
            'filters' => ['record' => $record->value],
            'recordStates' => RecordState::optionsFor($user),
            'colors' => ColorSwatch::values(),
            'can' => [
                'create' => $user->can('create', Department::class),
            ],
            'canExport' => $exporter->allowsReport('departments', $user),
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
            ->hidingDeletedFrom($actor)
            ->with(['roles.permissions', 'departments:id'])
            ->orderBy('users.name')
            ->get();

        $canManageMembers = $actor->can('manageMembers', $department);

        return Inertia::render('departments/Show', [
            'department' => DepartmentResource::make($department->loadCount($this->counts($actor)))->resolve($request),
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
            'automation' => $this->automation($department, $actor),
            'can' => [
                'update' => $actor->can('update', $department),
                'delete' => $actor->can('delete', $department),
                'restore' => $actor->can('restore', $department),
                'manageMembers' => $canManageMembers,
            ],
        ]);
    }

    /**
     * Logical deletion: the department stops operating and keeps its members
     * (inert) and its history.
     */
    public function destroy(Request $request, Department $department, DeleteDepartment $deleteDepartment): RedirectResponse
    {
        Gate::authorize('delete', $department);

        $deleteDepartment->handle($department, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departamento {$department->name} eliminado. Sus miembros e historial se conservan."]);

        // Deleted from its own detail page by someone who cannot consult
        // deleted records: that page no longer exists for them.
        $leavesDetail = ! $request->user()->canViewDeletedRecords()
            && parse_url(url()->previous(), PHP_URL_PATH) === parse_url(route('departments.show', $department), PHP_URL_PATH);

        return $leavesDetail ? to_route('departments.index') : back();
    }

    public function restore(Request $request, Department $department, RestoreDepartment $restoreDepartment): RedirectResponse
    {
        Gate::authorize('restore', $department);

        $restoreDepartment->handle($department, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Departamento {$department->name} restaurado."]);

        return back();
    }

    /**
     * Counters of a department, without deleted members or connections for
     * people who cannot consult deleted records.
     *
     * @return array<string, \Closure>
     */
    private function counts(User $actor): array
    {
        return app(DepartmentListQuery::class)->counts($actor);
    }

    public function update(DepartmentRequest $request, Department $department, UpdateDepartment $updateDepartment): RedirectResponse
    {
        $changed = $updateDepartment->handle($department, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Departamento actualizado.');

        return back();
    }

    /**
     * Connections authorized to route conversations to the department. The
     * association is edited from the connection form; only users who can
     * view connections receive a link to it. No credential is exposed.
     *
     * @return list<array{id: int, name: string, display_phone_number: string|null, is_active: bool, is_deleted: bool, is_default: bool, reopen_window_minutes: int|null, messages: array{welcome: bool, away: bool, farewell: bool}, health: array{value: string, label: string, tone: string}, url: string|null}>
     */
    private function connections(Department $department, User $actor): array
    {
        $canOpen = Route::has('connections.show') && $actor->holdsPermission(Permission::ViewConnections);

        return array_values($department->connections()
            ->hidingDeletedFrom($actor)
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
                    'is_deleted' => $connection->isDeleted(),
                    'is_default' => $connection->default_department_id === $department->id,
                    'reopen_window_minutes' => $connection->reopen_window_minutes,
                    'messages' => [
                        'welcome' => $connection->welcome_enabled && filled($connection->welcome_message),
                        'away' => $connection->away_enabled && filled($connection->away_message),
                        'farewell' => $connection->farewell_enabled && filled($connection->farewell_message),
                    ],
                    'health' => ['value' => $health->value, 'label' => $health->label(), 'tone' => $health->tone()],
                    'url' => $canOpen ? route('connections.show', $connection) : null,
                ];
            })
            ->all());
    }

    /**
     * Read-only context of how the department operates today: whether it is
     * attending right now under which schedule, the next non-working days,
     * and the load limits: the platform-wide one (configured in Configuración)
     * and the department's own per agent limit, which takes precedence.
     *
     * @return array{hours: array{mode: string, is_open: bool, next_opening: string|null, holiday: string|null, timezone: string}, holidays: list<array{date: string, label: string, recurring: bool}>, load_limit: int|null, department_limit: int|null, settings_url: string|null}
     */
    private function automation(Department $department, User $actor): array
    {
        $hours = app(BusinessHours::class);
        $today = now()->setTimezone($hours->timezone())->format('Y-m-d');

        return [
            'hours' => $hours->describe($department),
            'holidays' => array_slice(array_values(array_filter(
                $hours->holidays(),
                fn (array $holiday): bool => $holiday['recurring'] || $holiday['date'] >= $today,
            )), 0, 5),
            'load_limit' => app(ConversationAccess::class)->attentionLimit(),
            'department_limit' => $department->max_open_conversations_per_agent,
            'settings_url' => Route::has('admin.settings.edit') && $actor->holdsPermission(Permission::ViewSettings)
                ? route('admin.settings.edit')
                : null,
        ];
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
                ->hidingDeletedFrom($actor)
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
