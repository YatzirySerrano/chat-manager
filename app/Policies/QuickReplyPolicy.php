<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\QuickReply;
use App\Models\User;

/**
 * quick-replies.manage is not global-only: a manager with a narrower scope
 * may only manage replies restricted to their own departments, never global
 * replies or replies shared with departments they do not belong to.
 */
class QuickReplyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseQuickReplies)
            || $actor->holdsPermission(Permission::ManageQuickReplies);
    }

    /**
     * Insert replies in a conversation (the "/" picker).
     */
    public function use(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseQuickReplies);
    }

    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageQuickReplies);
    }

    /**
     * A deleted reply is read-only until it is restored.
     */
    public function update(User $actor, QuickReply $quickReply): bool
    {
        return ! $quickReply->isDeleted() && $this->manages($actor, $quickReply);
    }

    /**
     * Logical deletion: the shortcut stays reserved.
     */
    public function delete(User $actor, QuickReply $quickReply): bool
    {
        return $this->update($actor, $quickReply);
    }

    public function restore(User $actor, QuickReply $quickReply): bool
    {
        return $quickReply->isDeleted()
            && $actor->canViewDeletedRecords()
            && $this->manages($actor, $quickReply);
    }

    /**
     * Whether the actor may make replies available to every department.
     */
    public function manageGlobal(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageQuickReplies)
            && $actor->dataScope() === DataScope::Global;
    }

    private function manages(User $actor, QuickReply $quickReply): bool
    {
        if (! $actor->holdsPermission(Permission::ManageQuickReplies)) {
            return false;
        }

        if ($actor->dataScope() === DataScope::Global) {
            return true;
        }

        if ($quickReply->is_global) {
            return false;
        }

        $departmentIds = $quickReply->relationLoaded('departments')
            ? $quickReply->departments->modelKeys()
            : $quickReply->departments()->pluck('departments.id')->all();

        $departmentIds = array_map('intval', $departmentIds);

        return $departmentIds !== [] && array_diff($departmentIds, $actor->departmentIds()) === [];
    }
}
