<?php

namespace App\Enums;

/**
 * How trustworthy an origin attribution is. Provider confirmed data always
 * wins over a manual declaration when both exist.
 */
enum AttributionReliability: string
{
    /** Sent by the provider itself (for example, a Meta referral). */
    case ProviderConfirmed = 'provider_confirmed';

    /** Declared by a person from the interface. */
    case ManuallyDeclared = 'manually_declared';

    public function label(): string
    {
        return match ($this) {
            self::ProviderConfirmed => 'Confirmado por Meta',
            self::ManuallyDeclared => 'Declarado manualmente',
        };
    }

    /**
     * Higher ranks take precedence when resolving the current origin.
     */
    public function rank(): int
    {
        return match ($this) {
            self::ProviderConfirmed => 2,
            self::ManuallyDeclared => 1,
        };
    }
}
