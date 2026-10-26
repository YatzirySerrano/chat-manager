<?php

namespace App\Actions\Departments;

use App\Enums\RecordState;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;

/**
 * The departments list as the departments screen shows it: global roles
 * see every department, other roles only the ones they belong to; the
 * record state (deleted departments only for people who can consult them),
 * member, leader and connection counters without deleted records for
 * people who cannot consult them, active departments first by name.
 *
 * Shared by the departments screen and the departments report.
 *
 * @phpstan-type DepartmentFilters array{record: RecordState}
 */
class DepartmentListQuery
{
    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return DepartmentFilters
     */
    public function filters(User $actor, array $validated): array
    {
        return ['record' => RecordState::resolveFor($actor, $validated['record'] ?? null)];
    }

    /**
     * @param  DepartmentFilters  $filters
     * @return Builder<Department>
     */
    public function handle(User $actor, array $filters): Builder
    {
        return Department::query()
            ->visibleTo($actor)
            ->inRecordState($filters['record'])
            ->withCount($this->counts($actor))
            ->with(['leaders' => fn (BelongsToMany $query) => $query
                ->withoutProtectedFor($actor)
                ->select('users.id', 'users.name')
                ->orderBy('users.name')])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Counters of a department, without deleted members or connections for
     * people who cannot consult deleted records.
     *
     * @return array<string, Closure>
     */
    public function counts(User $actor): array
    {
        return [
            'members' => fn (Builder $members) => $this->hidePeople($members, $actor),
            'leaders' => fn (Builder $leaders) => $this->hidePeople($leaders, $actor),
            'connections' => fn (Builder $connections) => $this->hideDeleted($connections, $actor),
        ];
    }

    /**
     * Deleted people for whoever cannot consult deleted records, and
     * protected accounts (root role) for anyone but the root authority.
     *
     * @param  Builder<User>  $query
     */
    private function hidePeople(Builder $query, User $actor): void
    {
        $this->hideDeleted($query->withoutProtectedFor($actor), $actor);
    }

    /**
     * @param  Builder<User>|Builder<Connection>  $query
     */
    private function hideDeleted(Builder $query, User $actor): void
    {
        $query->hidingDeletedFrom($actor);
    }
}
