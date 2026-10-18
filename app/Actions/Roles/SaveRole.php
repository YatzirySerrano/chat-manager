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
        $saved = DB::transaction(function () use ($role, $data, $actor): Role {
            $creating = $role === null;
            $role ??= new Role(['guard_name' => 'web', 'name' => $this->uniqueName($data['label'])]);
            $role->loadMissing('permissions');

            $previous = $creating ? [] : $role->permissionNames();
            $preservedUnavailable = array_values(array_filter(
                $previous,
                fn (string $name): bool => ! (Permission::tryFrom($name)?->isAvailable() ?? false),
            ));
            $permissions = array_values(array_unique([...$data['permissions'], ...$preservedUnavailable]));

            $role->fill([
                'label' => $data['label'],
                'description' => $data['description'],
                'scope' => $data['scope'],
            ]);
            $changes = $creating ? [] : $this->attributeChanges($role);

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

            $added = array_values(array_diff($permissions, $previous));
            $removed = array_values(array_diff($previous, $permissions));

            if ($added !== [] || $removed !== []) {
                $this->audit->record(AuditAction::RolePermissionsChanged, $role, [
                    'added' => $added,
                    'removed' => $removed,
                    'users_affected' => $role->users()->count(),
                ], $actor);
            }

            return $role;
        });

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
