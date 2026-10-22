<?php

namespace App\Broadcasting\TeamChat;

use App\Broadcasting\RealtimeAudience;
use App\Enums\Permission;
use App\Models\User;

/**
 * Presence channel "team" (subscribed as "presence-team") for the online
 * indicators of the internal chat. Members only learn each other's id, name
 * and declared availability: nothing about chats or conversations.
 */
class TeamPresenceChannel
{
    public const NAME = 'team';

    public function __construct(private RealtimeAudience $audience) {}

    /**
     * @return array{id: int, name: string, availability: string}|false
     */
    public function join(User $user): array|false
    {
        $fresh = $this->audience->freshUser($user);

        if ($fresh === null || ! $fresh->holdsPermission(Permission::UseTeamChat)) {
            return false;
        }

        return [
            'id' => $fresh->id,
            'name' => $fresh->name,
            'availability' => $fresh->availability->value,
        ];
    }
}
