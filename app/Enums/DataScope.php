<?php

namespace App\Enums;

/**
 * The data a role can act upon. Permissions decide WHAT a user can do; the
 * scope decides ON WHICH records (organization, own departments, or only what
 * is assigned to the user).
 */
enum DataScope: string
{
    case Global = 'global';
    case Departments = 'departments';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Toda la organización',
            self::Departments => 'Sus departamentos',
            self::Assigned => 'Solo lo asignado',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Global => 'Actúa sobre todos los departamentos, usuarios y conversaciones.',
            self::Departments => 'Actúa únicamente sobre los departamentos a los que pertenece.',
            self::Assigned => 'Actúa únicamente sobre los registros asignados a la persona.',
        };
    }

    /**
     * Breadth of the scope, used to prevent granting a wider scope than one's own.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Global => 3,
            self::Departments => 2,
            self::Assigned => 1,
        };
    }

    public function covers(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * Global users already reach every department, so membership only applies to narrower scopes.
     */
    public function allowsDepartmentMembership(): bool
    {
        return $this !== self::Global;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $scope): array => [
            'value' => $scope->value,
            'label' => $scope->label(),
            'description' => $scope->description(),
        ], self::cases());
    }
}
