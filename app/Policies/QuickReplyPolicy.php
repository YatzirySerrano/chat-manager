<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
    public function update(User $actor, QuickReply $quickReply): Response
    {
        $response = $this->manages($actor, $quickReply);

        return $response->allowed() && $quickReply->isDeleted() ? Response::deny() : $response;
    }

    /**
     * Logical deletion: the shortcut stays reserved.
     */
    public function delete(User $actor, QuickReply $quickReply): Response
    {
        return $this->update($actor, $quickReply);
    }

    public function restore(User $actor, QuickReply $quickReply): Response
    {
        $response = $this->manages($actor, $quickReply);

        return $response->allowed() && ! $quickReply->isDeleted() ? Response::deny() : $response;
    }

    /**
     * Whether the actor may make replies available to every department.
     */
    public function manageGlobal(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageQuickReplies)
            && $actor->dataScope() === DataScope::Global;
    }

    /**
     * Without quick-replies.manage the answer is 403. A reply the actor
     * cannot even use (shared only with other departments, or deleted for
     * someone who cannot consult deleted records) answers like an id that
     * does not exist; a visible one outside the actor's reach is a 403.
     */
    private function manages(User $actor, QuickReply $quickReply): Response
    {
        if (! $actor->holdsPermission(Permission::ManageQuickReplies)) {
            return Response::deny();
        }

        if ($quickReply->isDeleted() && ! $actor->canViewDeletedRecords()) {
            return Response::denyAsNotFound();
        }

        if ($actor->dataScope() === DataScope::Global) {
            return Response::allow();
        }

        if ($quickReply->is_global) {
            return Response::deny();
        }

        $departmentIds = $quickReply->relationLoaded('departments')
            ? $quickReply->departments->modelKeys()
            : $quickReply->departments()->pluck('departments.id')->all();

        $departmentIds = array_map('intval', $departmentIds);
        $actorDepartmentIds = $actor->departmentIds();

        if (array_intersect($departmentIds, $actorDepartmentIds) === []) {
            return Response::denyAsNotFound();
        }

        return array_diff($departmentIds, $actorDepartmentIds) === [] ? Response::allow() : Response::deny();
    }
}
