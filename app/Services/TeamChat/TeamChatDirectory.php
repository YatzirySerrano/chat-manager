<?php

namespace App\Services\TeamChat;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may start a chat (or add to a group) whom.
 *
 * - Only active accounts whose active role grants team-chat.use take part;
 *   deleted accounts are never offered nor added.
 * - Users with organization-wide scope, or holding team-chat.cross-department,
 *   reach every such account.
 * - Everybody else only reaches people sharing at least one department.
 *
 * - Protected accounts (root role) are only reachable by the root
 *   authority: for anyone else they do not exist.
 *
 * This only governs starting chats and adding people: an existing thread
 * stays available to its current participants.
 */
class TeamChatDirectory
{
    public function reachesEveryone(User $actor): bool
    {
        return $actor->dataScope() === DataScope::Global
            || $actor->holdsPermission(Permission::UseCrossDepartmentTeamChat);
    }

    /**
     * People the actor may start a chat with, excluding the actor.
     *
     * @return Builder<User>
     */
    public function contactableUsers(User $actor): Builder
    {
        $query = User::query()
            ->active()
            ->whereKeyNot($actor->id)
            ->withoutProtectedFor($actor)
            ->withPermission(Permission::UseTeamChat);

        if (! $this->reachesEveryone($actor)) {
            $departmentIds = $actor->departmentIds();

            $query->whereHas('departments', fn (Builder $departments) => $departments->whereKey($departmentIds === [] ? [0] : $departmentIds));
        }

        return $query;
    }

    public function canContact(User $actor, User|int $target): bool
    {
        $targetId = $target instanceof User ? $target->id : $target;

        return $this->contactableUsers($actor)->whereKey($targetId)->exists();
    }

    /**
     * The subset of the given ids the actor may contact.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function contactableAmong(User $actor, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(array_map('intval', $this->contactableUsers($actor)->whereKey($userIds)->pluck('id')->all()));
    }
}
