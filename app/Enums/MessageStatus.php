<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
