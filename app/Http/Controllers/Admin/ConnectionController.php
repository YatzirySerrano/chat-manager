<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\CreateConnection;
use App\Actions\Connections\UpdateConnection;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectionRequest;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    /**
     * Connections filtered by text, channel, department and status. Health is
     * derived in PHP from recorded evidence, so that filter is applied after
     * the query; a company has few connections, so the list is not paginated.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Connection::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', Rule::enum(ConnectionChannel::class)],
            'health' => ['nullable', Rule::enum(ConnectionHealth::class)],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $channel = $filters['channel'] ?? null;
        $health = $filters['health'] ?? null;
        $departmentId = isset($filters['department']) ? (int) $filters['department'] : null;
        $status = $filters['status'] ?? null;

        $all = Connection::query()->visibleTo($actor)->get();

        $connections = Connection::query()
            ->visibleTo($actor)
            ->with(['departments' => fn (BelongsToMany $query) => $query
                ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                ->orderBy('departments.name')])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('display_phone_number', 'like', $term)
                    ->orWhere('verified_name', 'like', $term)
                    ->orWhere('phone_number_id', 'like', $term)
                    ->orWhere('business_account_id', 'like', $term));
            })
            ->when($channel !== null, fn (Builder $query) => $query->where('channel', $channel))
            ->when($departmentId !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($departmentId),
            ))
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->when($health !== null, fn (Collection $items) => $items
                ->filter(fn (Connection $connection): bool => $connection->health()->value === $health)
                ->values());

        return Inertia::render('connections/Index', [
            'connections' => ConnectionResource::collection($connections)->resolve($request),
            'filters' => [
                'search' => $search,
                'channel' => $channel,
                'health' => $health,
                'department' => $departmentId,
                'status' => $status,
            ],
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
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Connection::class);

        return Inertia::render('connections/Form', [
            'connection' => null,
            ...$this->formOptions(),
            'can' => ['update' => true, 'changeStatus' => false, 'verify' => false],
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

        return Inertia::render('connections/Form', [
            'connection' => ConnectionResource::make($connection)->resolve($request),
            ...$this->formOptions(),
            'can' => [
                'update' => $actor->can('update', $connection),
                'changeStatus' => $actor->can('changeStatus', $connection),
                'verify' => $actor->can('verify', $connection),
            ],
        ]);
    }

    public function update(ConnectionRequest $request, Connection $connection, UpdateConnection $updateConnection): RedirectResponse
    {
        $updateConnection->handle($connection, $request->payload(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conexión actualizada.']);

        return back();
    }

    /**
     * @return array{channel: array{value: string, label: string}, provider: array{value: string, label: string}, departments: list<array{id: int, name: string, color: string, is_active: bool}>, limits: array{message: int, reopen_window: int}, graphVersion: string}
     */
    private function formOptions(): array
    {
        return [
            'channel' => ['value' => ConnectionChannel::Whatsapp->value, 'label' => ConnectionChannel::Whatsapp->label()],
            'provider' => ['value' => ConnectionProvider::MetaCloudApi->value, 'label' => ConnectionProvider::MetaCloudApi->label()],
            'departments' => $this->departmentOptions(),
            'limits' => [
                'message' => ConnectionRequest::MESSAGE_MAX_LENGTH,
                'reopen_window' => ConnectionRequest::REOPEN_WINDOW_MAX_MINUTES,
            ],
            'graphVersion' => (string) config('services.meta.graph_version'),
        ];
    }

    /**
     * @return list<array{id: int, name: string, color: string, is_active: bool}>
     */
    private function departmentOptions(): array
    {
        return array_values(Department::query()
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
