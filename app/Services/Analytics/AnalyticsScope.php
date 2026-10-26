<?php

namespace App\Services\Analytics;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Which operational figures a user may aggregate, by the data scope of the
 * role (never by role name):
 * - global scope: the whole organization;
 * - departments scope: conversations currently in its departments, plus
 *   the ones it attends itself;
 * - assigned scope: only the conversations it attends (its own metrics).
 *
 * It applies to the metrics table and to the live conversations table
 * alike: both name the agent and the department columns.
 */
class AnalyticsScope
{
    /**
     * Restrict a query to the user's reach.
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function apply(QueryBuilder $query, User $user, string $agentColumn, string $departmentColumn): QueryBuilder
    {
        match ($user->dataScope()) {
            DataScope::Global => null,
            DataScope::Departments => $query->where(fn (QueryBuilder $visible) => $visible
                ->where($agentColumn, $user->id)
                ->orWhereIn($departmentColumn, $user->departmentIds())),
            DataScope::Assigned => $query->where($agentColumn, $user->id),
            null => $query->whereRaw('1 = 0'),
        };

        return $query;
    }

    /**
     * Whether the user only reaches their own figures.
     */
    public function isPersonal(User $user): bool
    {
        return ! in_array($user->dataScope(), [DataScope::Global, DataScope::Departments], true);
    }

    /**
     * "all" | "departments" | "own", as the dashboard names it.
     *
     * @return 'all'|'departments'|'own'
     */
    public function label(User $user): string
    {
        return match ($user->dataScope()) {
            DataScope::Global => 'all',
            DataScope::Departments => 'departments',
            default => 'own',
        };
    }

    /**
     * Stable fingerprint of everything that decides the user's reach, for
     * cache keys: two users with the same fingerprint see the same figures.
     * The root authority is part of it on its own (it reveals protected
     * accounts in the breakdowns), never inferred from the permissions.
     */
    public function fingerprint(User $user): string
    {
        $departments = $user->departmentIds();
        sort($departments);

        return hash('xxh128', implode('|', [
            $user->dataScope()->value ?? 'none',
            $user->dataScope() === DataScope::Global ? 'org' : (string) $user->id,
            implode(',', $departments),
            implode(',', $user->effectivePermissions()),
            $user->hasRootAuthority() ? 'root' : '',
        ]));
    }

    /**
     * Departments the user may filter by.
     *
     * @return list<array{id: int, name: string}>
     */
    public function departmentOptions(User $user): array
    {
        if ($this->isPersonal($user)) {
            return [];
        }

        return array_values(Department::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])
            ->all());
    }

    /**
     * Agents the user may filter by: people able to attend conversations
     * within the user's reach (protected root accounts only for the root
     * authority). Personal reach has no agent filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function agentOptions(User $user): array
    {
        if ($this->isPersonal($user)) {
            return [];
        }

        return array_values(User::query()
            ->visibleTo($user)
            ->withoutProtectedFor($user)
            ->active()
            ->withPermission(Permission::ReplyConversations)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $agent): array => ['id' => $agent->id, 'name' => $agent->name])
            ->all());
    }

    /**
     * Connections the user may filter by.
     *
     * @return list<array{id: int, name: string}>
     */
    public function connectionOptions(User $user): array
    {
        return array_values(Connection::query()
            ->visibleTo($user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Connection $connection): array => ['id' => $connection->id, 'name' => $connection->name])
            ->all());
    }
}
