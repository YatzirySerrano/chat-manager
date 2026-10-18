<?php

namespace App\Actions\Users;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Role;

/**
 * Consequences of moving a user from one role to another, computed on the
 * server both for the preview and again when the change is confirmed.
 */
final readonly class RoleChangeImpact
{
    /**
     * @param  list<string>  $lostPermissions
     * @param  list<string>  $gainedPermissions
     * @param  list<Department>  $currentDepartments
     * @param  list<Department>  $departmentsToLeave
     * @param  list<string>  $blockers
     */
    public function __construct(
        public ?Role $from,
        public Role $to,
        public array $lostPermissions,
        public array $gainedPermissions,
        public array $currentDepartments,
        public array $departmentsToLeave,
        public int $conversationsToRelease,
        public array $blockers,
    ) {}

    public function scopeChanged(): bool
    {
        return $this->from?->scope !== $this->to->scope;
    }

    /**
     * Whether the change alters permissions, visibility or responsibilities
     * enough to require an explicit confirmation.
     */
    public function isSignificant(): bool
    {
        return $this->lostPermissions !== []
            || $this->scopeChanged()
            || $this->departmentsToLeave !== []
            || $this->conversationsToRelease > 0
            || ($this->from->is_root ?? false) !== $this->to->is_root;
    }

    /**
     * Stable digest of the impact. The client sends back the digest it showed
     * the user, so a change confirmed on stale information is rejected.
     */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->from?->id,
            $this->to->id,
            $this->lostPermissions,
            $this->gainedPermissions,
            $this->to->scope->value,
            array_map(fn (Department $department): int => $department->id, $this->departmentsToLeave),
            $this->conversationsToRelease,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from === null ? null : self::describeRole($this->from),
            'to' => self::describeRole($this->to),
            'lost_permissions' => self::describePermissions($this->lostPermissions),
            'gained_permissions' => self::describePermissions($this->gainedPermissions),
            'scope_changed' => $this->scopeChanged(),
            'current_departments' => array_map(self::describeDepartment(...), $this->currentDepartments),
            'departments_to_leave' => array_map(self::describeDepartment(...), $this->departmentsToLeave),
            'conversations_to_release' => $this->conversationsToRelease,
            'blockers' => $this->blockers,
            'significant' => $this->isSignificant(),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * @return array{id: int, label: string, scope: string, scope_label: string, is_root: bool}
     */
    private static function describeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'label' => $role->label,
            'scope' => $role->scope->value,
            'scope_label' => $role->scope->label(),
            'is_root' => $role->is_root,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function describeDepartment(Department $department): array
    {
        return ['id' => $department->id, 'name' => $department->name];
    }

    /**
     * @param  list<string>  $names
     * @return list<array{value: string, label: string, group: string, sensitive: bool}>
     */
    private static function describePermissions(array $names): array
    {
        $described = [];

        foreach ($names as $name) {
            $permission = Permission::tryFrom($name);

            if ($permission === null || ! $permission->isAvailable()) {
                continue;
            }

            $described[] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group()->label(),
                'sensitive' => $permission->isSensitive(),
            ];
        }

        return $described;
    }
}
