<?php

namespace App\Broadcasting\TeamChat;

use App\Broadcasting\RealtimeChannels;
use App\Models\User;

/**
 * Authorizes "presence-team.departments.{departmentId}" for the members of
 * the department who use the internal chat (see TeamPresenceChannel).
 */
class TeamDepartmentPresenceChannel
{
    public function __construct(private TeamPresenceChannel $presence) {}

    /**
     * @return array{id: int}|false
     */
    public function join(User $user, string $departmentId): array|false
    {
        $id = RealtimeChannels::identifier($departmentId);

        return $id === null ? false : $this->presence->joinDepartment($user, $id);
    }
}
