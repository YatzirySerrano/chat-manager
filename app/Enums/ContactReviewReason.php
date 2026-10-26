<?php

namespace App\Enums;

/**
 * Why a contact was sent to administrative review.
 */
enum ContactReviewReason: string
{
    /**
     * A WhatsApp sender matched contacts registered by hand by phone only, and
     * the match was ambiguous (several candidates, or a candidate that already
     * has another WhatsApp identity). A new contact was registered instead.
     */
    case AmbiguousIdentityMatch = 'ambiguous_identity_match';

    /**
     * Registered by hand with the phone or email of contacts the employee
     * cannot see. The employee was not told, so the form cannot be used to
     * probe phones of other departments.
     */
    case PossibleDuplicate = 'possible_duplicate';

    public function label(): string
    {
        return match ($this) {
            self::AmbiguousIdentityMatch => 'Alguien escribió por WhatsApp y se parece a varios contactos',
            self::PossibleDuplicate => 'Contacto agregado a mano que podría estar repetido',
        };
    }
}
