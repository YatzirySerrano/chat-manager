<?php

namespace App\Enums;

/**
 * Result of a credit request once it was attended. «Solicitud completada»
 * only says the request was attended; the outcome says whether the credit
 * was approved (the only result that commissions) or rejected.
 */
enum ConversationOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Crédito aprobado',
            self::Rejected => 'Rechazada',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $outcome): array => [
            'value' => $outcome->value,
            'label' => $outcome->label(),
        ], self::cases());
    }
}
