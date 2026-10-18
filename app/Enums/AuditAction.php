<?php

namespace App\Enums;

enum AuditAction: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDepartmentsChanged = 'user.departments_changed';
    case UserPasswordReset = 'user.password_reset';
    case UserActivated = 'user.activated';
    case UserDeactivated = 'user.deactivated';
    case UserDeleted = 'user.deleted';
    case DepartmentCreated = 'department.created';
    case DepartmentUpdated = 'department.updated';
    case DepartmentActivated = 'department.activated';
    case DepartmentDeactivated = 'department.deactivated';
    case DepartmentMemberAdded = 'department.member_added';
    case DepartmentMemberRemoved = 'department.member_removed';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case RolePermissionsChanged = 'role.permissions_changed';
    case RoleActivated = 'role.activated';
    case RoleDeactivated = 'role.deactivated';
    case RoleDeleted = 'role.deleted';
    case SettingsUpdated = 'settings.updated';
    case AppearanceUpdated = 'appearance.updated';

    /**
     * Human readable description of the action.
     */
    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'Usuario creado',
            self::UserUpdated => 'Usuario actualizado',
            self::UserRoleChanged => 'Rol modificado',
            self::UserDepartmentsChanged => 'Departamentos del usuario modificados',
            self::UserPasswordReset => 'Contraseña restablecida por un administrador',
            self::UserActivated => 'Usuario activado',
            self::UserDeactivated => 'Usuario desactivado',
            self::UserDeleted => 'Usuario eliminado',
            self::DepartmentCreated => 'Departamento creado',
            self::DepartmentUpdated => 'Departamento actualizado',
            self::DepartmentActivated => 'Departamento activado',
            self::DepartmentDeactivated => 'Departamento desactivado',
            self::DepartmentMemberAdded => 'Miembro agregado al departamento',
            self::DepartmentMemberRemoved => 'Miembro retirado del departamento',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol actualizado',
            self::RolePermissionsChanged => 'Permisos del rol modificados',
            self::RoleActivated => 'Rol activado',
            self::RoleDeactivated => 'Rol desactivado',
            self::RoleDeleted => 'Rol eliminado',
            self::SettingsUpdated => 'Configuración actualizada',
            self::AppearanceUpdated => 'Apariencia global modificada',
        };
    }

    /**
     * Visual severity used by the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::UserDeleted, self::UserDeactivated, self::DepartmentDeactivated, self::DepartmentMemberRemoved,
            self::RoleDeactivated, self::RoleDeleted => 'danger',
            self::UserRoleChanged, self::UserPasswordReset, self::SettingsUpdated, self::RolePermissionsChanged,
            self::AppearanceUpdated => 'warning',
            self::UserCreated, self::UserActivated, self::DepartmentCreated, self::DepartmentActivated, self::DepartmentMemberAdded,
            self::RoleCreated, self::RoleActivated => 'success',
            default => 'neutral',
        };
    }

    /**
     * Options for select inputs.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $action): array => [
            'value' => $action->value,
            'label' => $action->label(),
        ], self::cases());
    }
}
