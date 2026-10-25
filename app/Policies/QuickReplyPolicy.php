<?php

namespace App\Policies;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Auth\Access\Response;

/**
 * Corporate replies: quick-replies.manage is not global-only, a manager with
 * a narrower scope may only manage replies restricted to their own
 * departments, never global replies or replies shared with departments they
 * do not belong to.
 *
 * Personal replies: only the owner manages them, while holding
 * quick-replies.personal (without it they stay usable but read-only).
 * Supervisors with supervision.view-personal consult those of the users in
 * their data scope and, with supervision.manage-personal, edit or delete the
 * ones of users they manage. For everyone else a personal reply answers like
 * an id that does not exist.
 */
class QuickReplyPolicy
{
    public function __construct(private PrivilegeGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseQuickReplies)
            || $actor->holdsPermission(Permission::ManageQuickReplies)
            || $actor->holdsPermission(Permission::CreatePersonalQuickReplies)
            || $this->superviseAny($actor);
    }

    /**
     * Insert replies in a conversation (the "/" picker).
     */
    public function use(User $actor): bool
    {
        return $actor->holdsPermission(Permission::UseQuickReplies);
    }

    /**
     * Create corporate replies (global or by department).
     */
    public function create(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ManageQuickReplies);
    }

    /**
     * Create one's own personal replies.
     */
    public function createPersonal(User $actor): bool
    {
        return $actor->holdsPermission(Permission::CreatePersonalQuickReplies);
    }

    /**
     * Consult the personal replies of other users of the actor's scope.
     */
    public function superviseAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewPersonalItems)
            && $actor->dataScope() !== null
            && $actor->dataScope() !== DataScope::Assigned;
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
        if ($quickReply->isPersonal()) {
            return $this->managesPersonal($actor, $quickReply);
        }

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

    /**
     * The owner needs quick-replies.personal; anybody else needs to supervise
     * the owner (view-personal within scope) to learn that the reply exists,
     * and manage-personal plus authority over the owner's account to change
     * it.
     */
    private function managesPersonal(User $actor, QuickReply $quickReply): Response
    {
        $isOwner = $quickReply->owner_user_id === $actor->id;

        if (! $isOwner && ! $this->supervises($actor, $quickReply)) {
            return Response::denyAsNotFound();
        }

        if ($quickReply->isDeleted() && ! $actor->canViewDeletedRecords()) {
            return Response::denyAsNotFound();
        }

        if ($isOwner) {
            return $actor->holdsPermission(Permission::CreatePersonalQuickReplies) ? Response::allow() : Response::deny();
        }

        $owner = $quickReply->owner;

        return $actor->holdsPermission(Permission::ManagePersonalItems) && $owner !== null && $this->guard->canManageUser($actor, $owner)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Whether the actor may consult this personal reply of another user.
     * A Superadministrador's replies stay out of reach of non-root
     * supervisors, as every personal item (PersonalItemSupervision::canView()).
     */
    private function supervises(User $actor, QuickReply $quickReply): bool
    {
        $owner = $quickReply->owner;

        return $owner !== null
            && $this->superviseAny($actor)
            && $this->guard->withinScope($actor, $owner)
            && ($actor->hasRootAuthority() || ! ($owner->role()->is_root ?? false));
    }
}
