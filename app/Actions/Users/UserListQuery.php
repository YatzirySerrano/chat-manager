<?php

namespace App\Actions\Users;

use App\Enums\RecordState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The users list as the users screen shows it: within the actor's data
 * scope, the record state (deleted accounts only for people who can consult
 * them), search over name and email, role, department and order.
 *
 * Shared by the users screen and the users report, so both always agree.
 *
 * @phpstan-type UserFilters array{search: string, role: int|null, department: int|null, record: RecordState, sort: string}
 */
class UserListQuery
{
    /**
     * Sort options exposed in the interface.
     *
     * @var array<string, array{0: string, 1: 'asc'|'desc'}>
     */
    public const SORTS = [
        'name' => ['name', 'asc'],
        'newest' => ['created_at', 'desc'],
        'last_login' => ['last_login_at', 'desc'],
    ];

    /**
     * Validation rules of the list filters (query string).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ];
    }

    /**
     * The filters the list applies for the actor, from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return UserFilters
     */
    public function filters(User $actor, array $validated): array
    {
        $search = $validated['search'] ?? null;
        $sort = $validated['sort'] ?? null;

        return [
            'search' => trim(is_string($search) ? $search : ''),
            'role' => is_numeric($validated['role'] ?? null) ? (int) $validated['role'] : null,
            'department' => is_numeric($validated['department'] ?? null) ? (int) $validated['department'] : null,
            'record' => RecordState::resolveFor($actor, $validated['record'] ?? null),
            'sort' => is_string($sort) && array_key_exists($sort, self::SORTS) ? $sort : 'name',
        ];
    }

    /**
     * @param  UserFilters  $filters
     * @return Builder<User>
     */
    public function handle(User $actor, array $filters): Builder
    {
        $search = $filters['search'];
        [$sortColumn, $sortDirection] = self::SORTS[$filters['sort']];

        return User::query()
            ->visibleTo($actor)
            ->inRecordState($filters['record'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $match) => $match->where('users.name', 'like', $term)->orWhere('users.email', 'like', $term));
            })
            ->when($filters['role'] !== null, fn (Builder $query) => $query->withRole($filters['role']))
            ->when($filters['department'] !== null, fn (Builder $query) => $query->whereHas(
                'departments',
                fn (Builder $departments) => $departments->whereKey($filters['department']),
            ))
            ->orderBy('users.'.$sortColumn, $sortDirection)
            ->orderBy('users.id');
    }
}
