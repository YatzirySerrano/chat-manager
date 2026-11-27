<?php

namespace App\Support;

use App\Enums\ConversationOutcome;
use App\Models\Conversation;

/**
 * The credit result of a request in words, the same everywhere it is read
 * (toasts, timeline, reports): «Crédito aprobado por $3,000», «Crédito
 * aprobado» (without amount) or «Rechazada».
 */
final class OutcomeText
{
    public static function of(Conversation $conversation): ?string
    {
        return self::spell($conversation->outcome, $conversation->outcome_amount);
    }

    public static function spell(?ConversationOutcome $outcome, int|float|string|null $amount): ?string
    {
        if ($outcome === null) {
            return null;
        }

        return $outcome === ConversationOutcome::Approved && $amount !== null && $amount !== ''
            ? $outcome->label().' por '.self::money($amount)
            : $outcome->label();
    }

    /**
     * «$3,000» or «$3,000.50» (cents only when there are).
     */
    public static function money(int|float|string $amount): string
    {
        $value = (float) $amount;

        return '$'.number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    }
}
