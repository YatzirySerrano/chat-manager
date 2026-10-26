<?php

namespace App\Enums;

/**
 * Which information a role can consult or operate. Permissions decide WHICH
 * ACTION a user may take; the scope decides ON WHICH records of this
 * installation (every department, its own departments, or only what is
 * assigned to the user). The global scope never reaches beyond the
 * installation: there is a single organization.
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
            self::Global => 'Puede consultar y trabajar con la información de todos los departamentos, usuarios y conversaciones.',
            self::Departments => 'Solo ve y trabaja con la información de los departamentos a los que pertenece.',
            self::Assigned => 'Solo ve y trabaja con lo que tiene asignado a su nombre y con lo pendiente que puede tomar.',
        };
    }

    /**
     * A concrete case of what the scope lets a person see. The assigned
     * example follows ConversationAccess: own conversations plus the pending
     * ones it may accept in its departments.
     */
    public function example(): string
    {
        return match ($this) {
            self::Global => 'Una gerente revisa las conversaciones y los reportes de todas las áreas.',
            self::Departments => 'Un supervisor de Ventas ve las conversaciones de Ventas, pero no las de Soporte.',
            self::Assigned => 'Un agente ve las conversaciones que atiende y las pendientes de sus departamentos que puede aceptar.',
        };
    }

    /**
     * Lucide icon name (kebab case) the interface maps to a component.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Global => 'building',
            self::Departments => 'users-round',
            self::Assigned => 'user-round-check',
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
     * @return list<array{value: string, label: string, description: string, example: string, icon: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $scope): array => [
            'value' => $scope->value,
            'label' => $scope->label(),
            'description' => $scope->description(),
            'example' => $scope->example(),
            'icon' => $scope->icon(),
        ], self::cases());
    }
}
