<?php

namespace App\Enums;

enum ConversationEventType: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Transferred = 'transferred';
    case Closed = 'closed';
    case Reopened = 'reopened';
}
