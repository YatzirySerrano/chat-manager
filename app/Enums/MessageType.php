<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contacts = 'contacts';
    case Template = 'template';
    case Interactive = 'interactive';
    case Reaction = 'reaction';
    case Unsupported = 'unsupported';
}
