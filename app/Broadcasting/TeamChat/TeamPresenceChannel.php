<?php

namespace App\Broadcasting\TeamChat;

use App\Broadcasting\RealtimeAudience;
use App\Enums\Permission;
use App\Models\User;
use App\Services\TeamChat\TeamChatDirectory;

/**
 * Online indicators of the internal chat ("En línea": the person has the
 * application open), separate from the declared availability.
 *
 * A presence channel tells every member who else is in it, so each channel
 * only gathers people who may all chat with each other according to
 * TeamChatDirectory:
 *
 * - "team" (presence-team): people who reach everyone (organization-wide
 *   scope or team-chat.cross-department);
 * - "team.departments.{id}": members of that department.
 *
 * Members only learn each other's id: names and availability keep coming
 * from the pages, which already apply the directory and the thread rules.
 */
class TeamPresenceChannel
{
    public const NAME = 'team';

    public const DEPARTMENT = 'team.departments.{departmentId}';

    public function __construct(
        private RealtimeAudience $audience,
        private TeamChatDirectory $directory,
    ) {}

    public static function department(int $departmentId): string
    {
        return "team.departments.{$departmentId}";
    }

    /**
     * @return array{id: int}|false
     */
    public function join(User $user): array|false
    {
        $fresh = $this->chatUser($user);

        return $fresh !== null && $this->directory->reachesEveryone($fresh) ? ['id' => $fresh->id] : false;
    }

    /**
     * @return array{id: int}|false
     */
    public function joinDepartment(User $user, int $departmentId): array|false
    {
        $fresh = $this->chatUser($user);

        return $fresh !== null && $fresh->belongsToDepartment($departmentId) ? ['id' => $fresh->id] : false;
    }

    /**
     * Presence channels the user should join (none without the chat).
     *
     * @return list<string>
     */
    public function channelsFor(User $user): array
    {
        if (! $user->holdsPermission(Permission::UseTeamChat)) {
            return [];
        }

        return [
            ...($this->directory->reachesEveryone($user) ? [self::NAME] : []),
            ...array_map(self::department(...), $user->departmentIds()),
        ];
    }

    private function chatUser(User $user): ?User
    {
        $fresh = $this->audience->freshUser($user);

        return $fresh !== null && $fresh->holdsPermission(Permission::UseTeamChat) ? $fresh : null;
    }
}
