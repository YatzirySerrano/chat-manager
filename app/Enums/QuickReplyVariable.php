<?php

namespace App\Enums;

/**
 * Fixed catalog of placeholders a quick reply may contain. They are replaced
 * by plain text; nothing else inside braces is ever evaluated.
 */
enum QuickReplyVariable: string
{
    case ContactName = 'contacto.nombre';
    case AgentName = 'agente.nombre';
    case DepartmentName = 'departamento.nombre';
    case CompanyName = 'empresa.nombre';

    /**
     * The placeholder as written in the body, e.g. "{contacto.nombre}".
     */
    public function token(): string
    {
        return '{'.$this->value.'}';
    }

    public function label(): string
    {
        return match ($this) {
            self::ContactName => 'Nombre del contacto',
            self::AgentName => 'Nombre del agente',
            self::DepartmentName => 'Nombre del departamento',
            self::CompanyName => 'Nombre de la empresa',
        };
    }

    /**
     * Sample value used by previews.
     */
    public function example(): string
    {
        return match ($this) {
            self::ContactName => 'María López',
            self::AgentName => 'Carlos Ruiz',
            self::DepartmentName => 'Ventas',
            self::CompanyName => 'Mi Empresa',
        };
    }

    /**
     * @return list<array{value: string, token: string, label: string, example: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $variable): array => [
            'value' => $variable->value,
            'token' => $variable->token(),
            'label' => $variable->label(),
            'example' => $variable->example(),
        ], self::cases());
    }
}
