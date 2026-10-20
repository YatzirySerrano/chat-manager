<?php

namespace App\Enums;

/**
 * What registering the sender of an inbound message did with the directory.
 */
enum InboundContactOutcome: string
{
    /** The sender's identity was already known; its contact was reused. */
    case Matched = 'matched';

    /** A new contact was registered automatically. */
    case Created = 'created';

    /** The identity was attached to the single contact registered by hand with the same phone. */
    case Linked = 'linked';
}
