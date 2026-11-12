<?php

namespace App\Support\Authorization;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * What PermissionMemo knows about one user: the role and direct permission
 * ids of the relations it was read from, and the answers already given.
 *
 * @internal
 */
final class PermissionMemoEntry
{
    /** @var array<string, bool> */
    public array $answers = [];

    /** @var array<int|string, true> */
    private array $roleIds = [];

    /** @var array<int|string, true> */
    private array $permissionIds = [];

    /**
     * @param  Collection<int, Model>  $roles
     * @param  Collection<int, Model>  $permissions
     * @param  Collection<int, Model>  $registered
     */
    public function __construct(
        private readonly Collection $roles,
        private readonly Collection $permissions,
        private readonly Collection $registered,
        public readonly string $guard,
    ) {
        foreach ($roles as $role) {
            $this->roleIds[$role->getKey()] = true;
        }

        foreach ($permissions as $permission) {
            $this->permissionIds[$permission->getKey()] = true;
        }
    }

    /**
     * Whether the entry was read from these very objects.
     *
     * @param  Collection<int, Model>|null  $roles
     * @param  Collection<int, Model>|null  $permissions
     * @param  Collection<int, Model>  $registered
     */
    public function matches(?Collection $roles, ?Collection $permissions, Collection $registered): bool
    {
        return $this->roles === $roles
            && $this->permissions === $permissions
            && $this->registered === $registered
            // A relation changed in place (push, forget) is a different reading.
            && count($roles) === count($this->roleIds)
            && count($permissions) === count($this->permissionIds);
    }

    /**
     * The package's rule: held directly, or through one of the user's roles.
     *
     * @param  array{id: int|string, roles: array<int|string, true>}|null  $permission
     */
    public function grants(?array $permission): bool
    {
        return $permission !== null
            && (isset($this->permissionIds[$permission['id']]) || array_intersect_key($this->roleIds, $permission['roles']) !== []);
    }
}
