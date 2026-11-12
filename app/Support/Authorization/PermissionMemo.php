<?php

namespace App\Support\Authorization;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use WeakMap;

/**
 * "Is this permission granted to this user?" answered from an index instead
 * of the package's per-check lookup.
 *
 * One page asks that question hundreds of times (the inbox resolves about 65
 * permission checks per listed conversation, the user list the whole catalog
 * for every row). The package answers each one by scanning its permission
 * collection through Eloquent attribute accessors, and by throwing and
 * catching PermissionDoesNotExist for policy abilities such as "view", which
 * its Gate::before hook also tries as permission names.
 *
 * The answer is the package's own rule, read from the same objects:
 * granted when the user's loaded `permissions` contain the permission, or
 * when one of the user's loaded `roles` is among the roles the registrar
 * holds for that permission; a name that is no permission of the user's
 * guard is never granted.
 *
 * Nothing is reused beyond what the package itself keeps in memory. The
 * index belongs to the registrar's loaded permission collection
 * (forgetCachedPermissions and clearPermissionsCollection drop it, so a role
 * edited anywhere, or the refresh before each queued job, starts a new one),
 * and a user's entry to the very `roles` and `permissions` relation objects
 * it was read from (assignRole, syncRoles, givePermissionTo... unset them).
 * Both live in WeakMaps: they disappear with their owner and are never
 * serialized with a model.
 */
final class PermissionMemo
{
    /** @var WeakMap<User, PermissionMemoEntry>|null */
    private static ?WeakMap $entries = null;

    /** @var WeakMap<Collection<int, Model>, array<string, array<string, array{id: int|string, roles: array<int|string, true>}>>>|null */
    private static ?WeakMap $indexes = null;

    /** @var (Closure(PermissionRegistrar): mixed)|null */
    private static ?Closure $peek = null;

    /**
     * @param  Closure(): bool  $uncached  The package's own check, used when the index cannot stand for it.
     */
    public static function check(User $user, string $name, Closure $uncached): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $registered = self::registered($registrar);
        $entries = self::$entries ??= new WeakMap;
        $entry = $entries[$user] ?? null;

        // Already answered from these very objects: the common case.
        if ($entry !== null && isset($entry->answers[$name]) && $registered !== null && $entry->matches(self::loaded($user, 'roles'), self::loaded($user, 'permissions'), $registered)) {
            return $entry->answers[$name];
        }

        // Teams, wildcards and lookups by id follow other rules.
        if ($registrar->teams || config('permission.enable_wildcard_permission') || PermissionRegistrar::isUid($name)) {
            return $uncached();
        }

        if ($registered === null) {
            $registrar->getPermissions();
            $registered = self::registered($registrar);

            if ($registered === null) {
                return $uncached();
            }
        }

        // Like the package, a name that is no permission at all ("view",
        // "update"...) is refused without reading the user's relations.
        if (! isset(self::index($registered)[''][$name])) {
            return false;
        }

        if ($entry === null || ! $entry->matches(self::loaded($user, 'roles'), self::loaded($user, 'permissions'), $registered)) {
            $user->loadMissing(['roles', 'permissions']);
            $roles = self::loaded($user, 'roles');
            $permissions = self::loaded($user, 'permissions');

            if ($roles === null || $permissions === null) {
                return $uncached();
            }

            $entry = new PermissionMemoEntry($roles, $permissions, $registered, Guard::getDefaultName($user));
            $entries[$user] = $entry;
        }

        return $entry->answers[$name] ??= $entry->grants(self::index($registered)[$entry->guard][$name] ?? null);
    }

    /**
     * Permissions of the registrar by guard and name, each with its id and
     * the ids of the roles that grant it. The empty guard lists every name.
     *
     * @param  Collection<int, Model>  $registered
     * @return array<string, array<string, array{id: int|string, roles: array<int|string, true>}>>
     */
    private static function index(Collection $registered): array
    {
        $indexes = self::$indexes ??= new WeakMap;

        if (isset($indexes[$registered])) {
            return $indexes[$registered];
        }

        $index = [];

        foreach ($registered as $permission) {
            $id = $permission->getKey();

            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $roles = [];
            $granting = $permission->relationLoaded('roles') ? $permission->getRelation('roles') : [];

            foreach ($granting as $role) {
                $roleId = $role->getKey();

                if (is_int($roleId) || is_string($roleId)) {
                    $roles[$roleId] = true;
                }
            }

            $name = (string) $permission->getAttribute('name');
            $index[(string) $permission->getAttribute('guard_name')][$name] = ['id' => $id, 'roles' => $roles];
            // Under the empty guard: every name that is a permission of some guard.
            $index[''][$name] ??= ['id' => $id, 'roles' => []];
        }

        return $indexes[$registered] = $index;
    }

    /**
     * @return Collection<int, Model>|null
     */
    private static function loaded(User $user, string $relation): ?Collection
    {
        $value = $user->relationLoaded($relation) ? $user->getRelation($relation) : null;

        return $value instanceof Collection ? $value : null;
    }

    /**
     * The permission collection the registrar holds in memory right now
     * (null before it is loaded and after it is forgotten).
     *
     * @return Collection<int, Model>|null
     */
    private static function registered(PermissionRegistrar $registrar): ?Collection
    {
        self::$peek ??= Closure::bind(
            static fn (PermissionRegistrar $registrar): mixed => $registrar->permissions,
            null,
            PermissionRegistrar::class,
        );

        $value = (self::$peek)($registrar);

        return $value instanceof Collection ? $value : null;
    }
}
