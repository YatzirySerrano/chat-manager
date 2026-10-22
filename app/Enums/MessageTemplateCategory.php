<?php

namespace App\Enums;

/**
 * Template category assigned by Meta. It determines Meta's pricing category,
 * which this application never turns into an amount.
 */
enum MessageTemplateCategory: string
{
    case Marketing = 'MARKETING';
    case Utility = 'UTILITY';
    case Authentication = 'AUTHENTICATION';

    public function label(): string
    {
        return match ($this) {
            self::Marketing => 'Marketing',
            self::Utility => 'Utilidad',
            self::Authentication => 'Autenticación',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category): array => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
