<?php

namespace App\Actions\Authorization;

use App\Actions\Roles\SaveRole;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ManualValidation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Brings the default roles of an existing installation up to their current
 * definition (DefaultRole), on request only (app:sync-permissions
 * --upgrade-default-roles). It is idempotent and keeps extra permissions,
 * but it does not remember withdrawals: a permission of the definition that
 * an administrator removed on purpose is added again, so the preview must be
 * reviewed before --apply.
 *
 *
 * - only the non-root roles named like a default role (administrator,
 *   supervisor, agent) are considered, plus the Demo B role when it is asked
 *   for explicitly (it follows the agent's definition); the root authority
 *   and custom roles are never touched;
 * - it adds the permissions of the definition the role lacks, except those
 *   reserved to the root authority and, for a role without the global
 *   scope, those that require it; sensitive ones are held back unless asked
 *   for;
 * - it only removes permissions the role could never exercise (reserved to
 *   the root authority, or global-only on a narrower scope); extra
 *   permissions granted from the interface are kept;
 * - changes go through SaveRole, which audits them (role.permissions_changed
 *   with what was added and removed), refreshes the permission cache and
 *   revalidates the open sessions of the holders. A role already up to date
 *   writes nothing.
 */
class UpgradeDefaultRoles
{
    public function __construct(
        private SaveRole $saveRole,
        private SyncRolesAndPermissions $sync,
    ) {}

    /**
     * What an upgrade would change, without writing anything.
     *
     * @return list<array{role: Role, definition: DefaultRole, users: int, add: list<string>, remove: list<string>, held_back: list<string>}>
     */
    public function plan(bool $includeDemo = false, bool $includeSensitive = false): array
    {
        $plan = [];

        foreach ($this->roles($includeDemo) as $role) {
            $definition = DefaultRole::tryFrom($role->name) ?? DefaultRole::Agent;
            $current = $role->permissionNames();

            $missing = array_values(array_filter(
                $definition->permissions(),
                fn (Permission $permission): bool => $this->mayHold($role, $permission) && ! in_array($permission->value, $current, true),
            ));
            $heldBack = fn (Permission $permission): bool => ! $includeSensitive && $permission->isSensitive();

            $plan[] = [
                'role' => $role,
                'definition' => $definition,
                'users' => (int) $role->users_count,
                'add' => $this->values(array_values(array_filter($missing, fn (Permission $permission): bool => ! $heldBack($permission)))),
                'remove' => array_values(array_filter($current, function (string $name) use ($role): bool {
                    $permission = Permission::tryFrom($name);

                    return $permission !== null && ! $this->mayHold($role, $permission);
                })),
                'held_back' => $this->values(array_values(array_filter($missing, $heldBack))),
            ];
        }

        return $plan;
    }

    /**
     * Apply the plan acting as the given root user. The catalog is
     * synchronized first, so every permission to grant exists.
     *
     * @return list<array{role: Role, definition: DefaultRole, users: int, add: list<string>, remove: list<string>, held_back: list<string>, changed: bool}>
     */
    public function apply(User $actor, bool $includeDemo = false, bool $includeSensitive = false): array
    {
        $this->sync->handle();

        $applied = [];

        foreach ($this->plan($includeDemo, $includeSensitive) as $entry) {
            $role = $entry['role'];
            $permissions = array_values(array_unique([
                ...array_diff($role->permissionNames(), $entry['remove']),
                ...$entry['add'],
            ]));

            $changed = ($entry['add'] !== [] || $entry['remove'] !== []) && $this->saveRole->update($role, [
                'label' => $role->label,
                'description' => $role->description,
                'scope' => $role->scope,
                'permissions' => $permissions,
            ], $actor);

            $applied[] = [...$entry, 'changed' => $changed];
        }

        return $applied;
    }

    /**
     * @return Collection<int, Role>
     */
    private function roles(bool $includeDemo): Collection
    {
        $names = array_map(
            fn (DefaultRole $role): string => $role->value,
            array_values(array_filter(DefaultRole::cases(), fn (DefaultRole $role): bool => ! $role->isRoot())),
        );

        return Role::query()
            ->where('guard_name', 'web')
            ->where('is_root', false)
            ->where(fn ($query) => $query
                ->whereIn('name', $names)
                ->when($includeDemo, fn ($demo) => $demo->orWhere('label', ManualValidation::ROLE_LABEL)))
            ->with('permissions')
            ->withCount('users')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether a non-root role of this scope may ever exercise the permission.
     */
    private function mayHold(Role $role, Permission $permission): bool
    {
        return ! $permission->isReservedToRoot()
            && (! $permission->requiresGlobalScope() || $role->scope === DataScope::Global);
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private function values(array $permissions): array
    {
        return array_map(fn (Permission $permission): string => $permission->value, $permissions);
    }
}
