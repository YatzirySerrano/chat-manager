<?php

namespace App\Enums;

/**
 * How the pending conversations of a department reach an agent.
 */
enum DistributionMode: string
{
    /** Shared queue: agents with permission accept pending conversations. */
    case Shared = 'shared';

    /** Only users with permission to assign hand conversations out. */
    case Manual = 'manual';

    /** Assigned on arrival to the available eligible agent with the lowest load. */
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Bandeja compartida',
            self::Manual => 'Asignación manual',
            self::Automatic => 'Asignación automática',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Shared => 'Las conversaciones nuevas quedan en «Pendientes» y cualquier agente del departamento con permiso puede tomarlas.',
            self::Manual => 'Solo quien tiene permiso de asignar entrega las conversaciones; los agentes no las aceptan por su cuenta.',
            self::Automatic => 'Cada conversación nueva se asigna al agente disponible con menos conversaciones activas; si nadie puede recibirla, queda en «Pendientes».',
        };
    }

    /**
     * Whether agents can take pending conversations of the department themselves.
     */
    public function allowsAccepting(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode): array => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'description' => $mode->description(),
        ], self::cases());
    }
}
