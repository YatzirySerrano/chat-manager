<?php

namespace App\Enums;

/**
 * What receiving a customer message did to the conversations.
 */
enum InboundMessageOutcome: string
{
    /** A new conversation was opened for the message. */
    case Created = 'created';

    /** The message joined the contact's unclosed conversation. */
    case Appended = 'appended';

    /** The last closed conversation was reopened by the reopening policy. */
    case Reopened = 'reopened';

    /** The message was sent before the conversation closed and arrived late: it joins its history without reopening it. */
    case LateArrival = 'late_arrival';

    /** The provider delivered a message already stored: nothing changed. */
    case Duplicate = 'duplicate';
}
