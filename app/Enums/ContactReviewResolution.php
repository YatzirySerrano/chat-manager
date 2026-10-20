<?php

namespace App\Enums;

/**
 * How an administrator closed a review case. Contacts are never deleted: a
 * duplicate is archived and keeps its history.
 */
enum ContactReviewResolution: string
{
    /** They are different people; both contacts stay as they are. */
    case DistinctPeople = 'distinct_people';

    /**
     * The WhatsApp identity, connections and provider evidence of the
     * automatically registered contact moved to the chosen existing contact,
     * and the automatic one was archived.
     */
    case IdentityLinked = 'identity_linked';

    /** The contact under review duplicates the chosen one and was archived. */
    case DuplicateArchived = 'duplicate_archived';

    public function label(): string
    {
        return match ($this) {
            self::DistinctPeople => 'Son personas distintas',
            self::IdentityLinked => 'WhatsApp vinculado a la ficha existente',
            self::DuplicateArchived => 'Duplicado archivado',
        };
    }
}
