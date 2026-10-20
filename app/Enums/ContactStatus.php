<?php

namespace App\Enums;

enum ContactStatus: string
{
    case Active = 'active';

    /** Hidden from the default lists; its history is kept and it can be restored. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Archived => 'Archivado',
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
