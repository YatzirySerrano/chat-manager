<?php

namespace App\Enums\TeamChat;

/**
 * A message written by a person, or a system line recording a change of the
 * group (created, renamed, participants added or removed...) that every
 * participant can see, so nothing about a group changes silently.
 */
enum TeamMessageKind: string
{
    case Message = 'message';
    case System = 'system';
}
