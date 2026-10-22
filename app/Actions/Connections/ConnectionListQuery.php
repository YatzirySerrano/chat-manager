<?php

namespace App\Actions\Connections;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\RecordState;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;

/**
 * The connections list as the connections screen shows it: filtered by
 * text, channel, department, operational status and record state (deleted
 * ones only for people who can consult them). Health is derived in PHP from
 * recorded evidence, so that filter is applied after the query; a company
 * has few connections.
 *
 * Shared by the connections screen and the connections report.
 *
 * @phpstan-type ConnectionFilters array{search: string, channel: string|null, health: string|null, department: int|null, status: string|null, record: RecordState}
 */
class ConnectionListQuery
{
    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', Rule::enum(ConnectionChannel::class)],
            'health' => ['nullable', Rule::enum(ConnectionHealth::class)],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return ConnectionFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $search = $validated['search'] ?? null;
        $channel = $validated['channel'] ?? null;
        $health = $validated['health'] ?? null;
        $status = $validated['status'] ?? null;

        return [
            'search' => trim(is_string($search) ? $search : ''),
            'channel' => is_string($channel) ? $channel : null,
            'health' => is_string($health) ? $health : null,
            'department' => is_numeric($validated['department'] ?? null) ? (int) $validated['department'] : null,
            'status' => is_string($status) ? $status : null,
            'record' => RecordState::resolveFor($actor, $validated['record'] ?? null),
        ];
    }

    /**
     * Filtered connections, except the health filter (see matching()).
     *
     * @param  ConnectionFilters  $filters
     * @return Builder<Connection>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $search = $filters['search'];

        return Connection::query()
            ->visibleTo($actor)
            ->inRecordState($filters['record'])
            ->withCount('conversations')
            ->with(['departments' => fn (BelongsToMany $query) => $query
                ->select('departments.id', 'departments.name', 'departments.color', 'departments.is_active')
                ->hidingDeletedFrom($actor)
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
            ->when($filters['channel'] !== null, fn (Builder $query) => $query->where('channel', $filters['channel']))
            ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($filters['department']),
            ))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * The connections of the list, with the health filter applied.
     *
     * @param  ConnectionFilters  $filters
     * @return Collection<int, Connection>
     */
    public function matching(User $actor, array $filters): Collection
    {
        return $this->withHealth($this->handle($actor, $filters)->get(), $filters['health']);
    }

    /**
     * @param  Collection<int, Connection>  $connections
     * @return Collection<int, Connection>
     */
    public function withHealth(Collection $connections, ?string $health): Collection
    {
        return $health === null
            ? $connections
            : $connections->filter(fn (Connection $connection): bool => $connection->health()->value === $health)->values();
    }
}
