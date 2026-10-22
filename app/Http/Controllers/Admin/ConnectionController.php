<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\ConnectionListQuery;
use App\Actions\Connections\CreateConnection;
use App\Actions\Connections\DeleteConnection;
use App\Actions\Connections\RestoreConnection;
use App\Actions\Connections\UpdateConnection;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Enums\RecordState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectionRequest;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\Exports\ReportExporter;
use App\Support\SaveToast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    /**
     * Connections filtered by text, channel, department, operational status
     * and record state (deleted ones only for people who can consult them).
     * Health is derived in PHP from recorded evidence, so that filter is
     * applied after the query; a company has few connections, so the list is
     * not paginated. Counters never include deleted connections.
     */
    public function index(Request $request, ConnectionListQuery $list, ReportExporter $exporter): Response
    {
        Gate::authorize('viewAny', Connection::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $list->filters($actor, $request->validate(ConnectionListQuery::rules()));
        $search = $filters['search'];
        $channel = $filters['channel'];
        $health = $filters['health'];
        $departmentId = $filters['department'];
        $status = $filters['status'];
        $record = $filters['record'];

        $all = Connection::query()->visibleTo($actor)->notDeleted()->get();

        $connections = $list->matching($actor, $filters);

        return Inertia::render('connections/Index', [
            'connections' => ConnectionResource::collection($connections)->resolve($request),
            'filters' => [
                'search' => $search,
                'channel' => $channel,
                'health' => $health,
                'department' => $departmentId,
                'status' => $status,
                'record' => $record->value,
            ],
            'recordStates' => RecordState::optionsFor($actor),
            'stats' => [
                'total' => $all->count(),
                'active' => $all->where('is_active', true)->count(),
                'verified' => $all->filter(fn (Connection $connection): bool => in_array(
                    $connection->configurationHealth(),
                    [ConnectionHealth::CredentialsVerified, ConnectionHealth::Receiving, ConnectionHealth::Operational],
                    true,
                ))->count(),
                'attention' => $all->filter(fn (Connection $connection): bool => in_array(
                    $connection->configurationHealth(),
                    [ConnectionHealth::NotConfigured, ConnectionHealth::CredentialsInvalid, ConnectionHealth::Error],
                    true,
                ))->count(),
            ],
            'channels' => ConnectionChannel::options(),
            'healthOptions' => ConnectionHealth::options(),
            'departments' => $this->departmentOptions(),
            'can' => [
                'create' => $actor->can('create', Connection::class),
                'manage' => $actor->can('create', Connection::class),
            ],
            'canExport' => $exporter->allowsReport('connections', $actor),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Connection::class);

        return Inertia::render('connections/Form', [
            'connection' => null,
            ...$this->formOptions(),
            'can' => ['update' => true, 'changeStatus' => false, 'verify' => false, 'delete' => false, 'restore' => false],
        ]);
    }

    public function store(ConnectionRequest $request, CreateConnection $createConnection): RedirectResponse
    {
        $connection = $createConnection->handle($request->payload(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Conexión {$connection->name} creada. Está inactiva hasta que la actives.",
        ]);

        return to_route('connections.show', $connection);
    }

    /**
     * Detail and edition of a connection. Users who can only view get the
     * same page in read-only mode.
     */
    public function show(Request $request, Connection $connection): Response
    {
        Gate::authorize('view', $connection);

        $actor = $request->user();

        $connection->load(['departments' => fn (BelongsToMany $query) => $query
            ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
            ->orderBy('departments.name')]);
        $connection->loadCount('conversations');

        return Inertia::render('connections/Form', [
            'connection' => ConnectionResource::make($connection)->resolve($request),
            ...$this->formOptions($connection),
            'can' => [
                'update' => $actor->can('update', $connection),
                'changeStatus' => $actor->can('changeStatus', $connection),
                'verify' => $actor->can('verify', $connection),
                'delete' => $actor->can('delete', $connection),
                'restore' => $actor->can('restore', $connection),
            ],
        ]);
    }

    public function update(ConnectionRequest $request, Connection $connection, UpdateConnection $updateConnection): RedirectResponse
    {
        $changed = $updateConnection->handle($connection, $request->payload(), $request->user());

        SaveToast::flash($changed, 'Conexión actualizada.');

        return back();
    }

    /**
     * Logical deletion: the connection stops all traffic and keeps its
     * conversations; its Phone number ID stays reserved.
     */
    public function destroy(Request $request, Connection $connection, DeleteConnection $deleteConnection): RedirectResponse
    {
        Gate::authorize('delete', $connection);

        $deleteConnection->handle($connection, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Conexión {$connection->name} eliminada. Sus conversaciones e historial se conservan."]);

        return to_route('connections.index');
    }

    /**
     * The restored connection stays inactive and must be verified again.
     */
    public function restore(Request $request, Connection $connection, RestoreConnection $restoreConnection): RedirectResponse
    {
        Gate::authorize('restore', $connection);

        $restoreConnection->handle($connection, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Conexión {$connection->name} restaurada. Está inactiva: verifica sus credenciales antes de activarla.",
        ]);

        return back();
    }

    /**
     * @return array{channel: array{value: string, label: string}, provider: array{value: string, label: string}, departments: list<array{id: int, name: string, color: string, is_active: bool}>, limits: array{message: int, reopen_window: int}, graphVersion: string}
     */
    private function formOptions(?Connection $connection = null): array
    {
        return [
            'channel' => ['value' => ConnectionChannel::Whatsapp->value, 'label' => ConnectionChannel::Whatsapp->label()],
            'provider' => ['value' => ConnectionProvider::MetaCloudApi->value, 'label' => ConnectionProvider::MetaCloudApi->label()],
            'departments' => $this->departmentOptions($connection),
            'limits' => [
                'message' => ConnectionRequest::MESSAGE_MAX_LENGTH,
                'reopen_window' => ConnectionRequest::REOPEN_WINDOW_MAX_MINUTES,
            ],
            'graphVersion' => (string) config('services.meta.graph_version'),
        ];
    }

    /**
     * Departments that can be chosen: the ones not deleted, plus those the
     * connection already has (a deleted one may stay but not be added).
     *
     * @return list<array{id: int, name: string, color: string, is_active: bool}>
     */
    private function departmentOptions(?Connection $connection = null): array
    {
        $attached = $connection?->departments->modelKeys() ?? [];

        return array_values(Department::query()
            ->where(fn (Builder $query) => $query->notDeleted()->orWhereIn('id', $attached))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'color', 'is_active'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color->value,
                'is_active' => $department->is_active,
            ])
            ->all());
    }
}
