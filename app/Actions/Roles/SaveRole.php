<?php

namespace App\Actions\Roles;

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates or updates a role and its permissions in a single transaction.
 * Permissions of modules that are not available yet are never touched.
 */
class SaveRole
{
    public function __construct(
        private AuditLogger $audit,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  array{label: string, description: string|null, scope: DataScope, permissions: list<string>}  $data
     */
    public function handle(?Role $role, array $data, User $actor): Role
    {
        $saved = $this->persist($role, $data, $actor);

        return $saved ?? $role ?? throw new LogicException('A new role is always saved.');
    }

    /**
     * Update an existing role. Nothing is written (no save, no permission
     * sync, no audit entry, no cache flush) when the attributes and the set
     * of permissions stay the same, whatever their order.
     *
     * @param  array{label: string, description: string|null, scope: DataScope, permissions: list<string>}  $data
     * @return bool Whether anything changed.
     */
    public function update(Role $role, array $data, User $actor): bool
    {
        return $this->persist($role, $data, $actor) !== null;
    }

    /**
     * @param  array{label: string, description: string|null, scope: DataScope, permissions: list<string>}  $data
     * @return Role|null The saved role, or null when an existing role did not change.
     */
    private function persist(?Role $role, array $data, User $actor): ?Role
    {
        $saved = DB::transaction(function () use ($role, $data, $actor): ?Role {
            $creating = $role === null;
            $role ??= new Role(['guard_name' => 'web', 'name' => $this->uniqueName($data['label'])]);
            $role->loadMissing('permissions');

            $previous = $creating ? [] : $role->permissionNames();
            // Unavailable permissions are kept, except reserved ones a
            // non-root role must never hold. The root role always keeps its
            // reserved permissions: the editor never offers them.
            $preservedUnavailable = array_values(array_filter(
                $previous,
                function (string $name) use ($role): bool {
                    $permission = Permission::tryFrom($name);

                    if ($permission === null) {
                        return true;
                    }

                    return $role->is_root
                        ? ! $permission->isAvailable() || $permission->isReservedToRoot()
                        : ! $permission->isAvailable() && ! $permission->isReservedToRoot();
                },
            ));
            $permissions = array_values(array_unique([...$data['permissions'], ...$preservedUnavailable]));

            $role->fill([
                'label' => $data['label'],
                'description' => $data['description'],
                'scope' => $data['scope'],
            ]);
            $changes = $creating ? [] : $this->attributeChanges($role);
            $added = array_values(array_diff($permissions, $previous));
            $removed = array_values(array_diff($previous, $permissions));

            if (! $creating && $changes === [] && $added === [] && $removed === []) {
                return null;
            }

            try {
                $role->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['label' => 'Ya existe un rol con ese nombre.']);
            }

            $role->syncPermissions($permissions);

            if ($creating) {
                $this->audit->record(AuditAction::RoleCreated, $role, [
                    'scope' => $role->scope->label(),
                    'permissions' => $permissions,
                ], $actor);

                return $role;
            }

            if ($changes !== []) {
                $this->audit->record(AuditAction::RoleUpdated, $role, ['changes' => $changes], $actor);
            }

            if ($added !== [] || $removed !== []) {
                $this->audit->record(AuditAction::RolePermissionsChanged, $role, [
                    'added' => $added,
                    'removed' => $removed,
                    'users_affected' => $role->users()->count(),
                ], $actor);
            }

            return $role;
        });

        if ($saved === null) {
            return null;
        }

        $this->registrar->forgetCachedPermissions();

        return $saved->load('permissions');
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function attributeChanges(Role $role): array
    {
        $changes = [];

        foreach (['label', 'description', 'scope'] as $attribute) {
            if ($role->isDirty($attribute)) {
                $old = $role->getOriginal($attribute);
                $new = $role->getAttribute($attribute);
                $changes[$attribute] = [
                    'old' => $old instanceof DataScope ? $old->value : $old,
                    'new' => $new instanceof DataScope ? $new->value : $new,
                ];
            }
        }

        return $changes;
    }

    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'rol';
        $name = $base;
        $suffix = 2;

        while (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            $name = "{$base}-{$suffix}";
            $suffix++;
        }

        return $name;
    }
}
