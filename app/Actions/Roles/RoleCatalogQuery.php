<?php

namespace App\Actions\Roles;

use App\Enums\RecordState;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The roles catalog as the roles screen lists it: the record state
 * (deleted roles only for people who can consult deleted records), the
 * permissions of each role and its users (deleted accounts only counted for
 * people who can consult them), the root authority first, then active roles
 * by name.
 *
 * Shared by the roles screen and the roles report, so both always agree.
 *
 * @phpstan-type RoleFilters array{record: RecordState}
 */
class RoleCatalogQuery
{
    /**
     * Validation rules of the catalog filters (query string).
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
     * @return RoleFilters
     */
    public function filters(User $actor, array $validated): array
    {
        return ['record' => RecordState::resolveFor($actor, $validated['record'] ?? null)];
    }

    /**
     * @param  RoleFilters  $filters
     * @return Builder<Role>
     */
    public function handle(User $actor, array $filters): Builder
    {
        return Role::query()
            ->inRecordState($filters['record'])
            ->with('permissions')
            ->withCount(['users' => fn (Builder $users) => $this->hideDeletedUsers($users, $actor)])
            ->orderByDesc('is_root')
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->orderBy('id');
    }

    /**
     * @param  Builder<User>  $users
     */
    private function hideDeletedUsers(Builder $users, User $actor): void
    {
        $users->hidingDeletedFrom($actor);
    }
}
