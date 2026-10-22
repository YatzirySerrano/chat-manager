<?php

namespace App\Enums;

enum ContactStatus: string
{
    case Active = 'active';

    /**
     * Logically deleted ("Eliminado"): hidden from the lists of people who
     * cannot consult deleted records; its history is kept and it can be
     * restored. The stored value keeps its historical name.
     */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Archived => 'Eliminado',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
