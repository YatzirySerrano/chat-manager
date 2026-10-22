<?php

namespace App\Enums\TeamChat;

/**
 * Broad family of an internal attachment, decided from its real MIME type.
 */
enum TeamAttachmentKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
}
