<?php

namespace App\Broadcasting;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;

/**
 * Who may listen to each real-time channel. Every rule is evaluated on a
 * user read again from the database (role, permissions, departments and
 * state), so a session opened before a permission was revoked, a
 * membership was removed or the account was deleted cannot subscribe
 * again with its old rights. Visibility is delegated to ConversationAccess,
 * the same rules as pages, lists, exports and attachments.
 */
class RealtimeAudience
{
    public function __construct(private ConversationAccess $access) {}

    /**
     * The user as stored now, or null when the account no longer exists or
     * was logically deleted.
     */
    public function freshUser(User|int $user): ?User
    {
        $fresh = User::query()
            ->with(['roles', 'departments'])
            ->whereKey($user instanceof User ? $user->id : $user)
            ->first();

        return $fresh !== null && ! $fresh->isDeleted() ? $fresh : null;
    }

    /**
     * A personal channel only carries signals about its owner, so it stays
     * available while the account is active, even without an active role
     * (it must still learn when its access is restored).
     */
    public function canJoinUser(User $user, int $userId): bool
    {
        return $user->id === $userId;
    }

    public function canJoinConversation(User $user, int $conversationId): bool
    {
        $conversation = Conversation::query()->find($conversationId);

        return $conversation !== null && $this->access->canView($user, $conversation);
    }

    /**
     * The team inbox of a department: users with a global scope, and members
     * of the department whose scope covers their departments' conversations.
     */
    public function canJoinDepartmentInbox(User $user, int $departmentId): bool
    {
        if (! $user->holdsPermission(Permission::ViewConversations)) {
            return false;
        }

        return match ($user->dataScope()) {
            DataScope::Global => Department::query()->whereKey($departmentId)->exists(),
            DataScope::Departments => $user->belongsToDepartment($departmentId),
            default => false,
        };
    }

    /**
     * The pending queue of a department: whoever may accept from it now.
     */
    public function canJoinDepartmentQueue(User $user, int $departmentId): bool
    {
        return in_array($departmentId, $this->access->acceptableDepartmentIds($user), true);
    }

    public function canJoinGlobalInbox(User $user): bool
    {
        return $user->holdsPermission(Permission::ViewConversations) && $user->dataScope() === DataScope::Global;
    }

    /**
     * Inbox channels the user should listen to, matching what their inbox
     * shows: everything (global scope), their departments (departments
     * scope) or the queues they may accept from (personal inbox). Their own
     * assignments always arrive on their personal channel.
     *
     * @return list<string>
     */
    public function inboxChannels(User $user): array
    {
        if (! $user->holdsPermission(Permission::ViewConversations)) {
            return [];
        }

        return match ($user->dataScope()) {
            DataScope::Global => [RealtimeChannels::globalInbox()],
            DataScope::Departments => array_map(RealtimeChannels::departmentInbox(...), $user->departmentIds()),
            default => array_map(RealtimeChannels::departmentQueue(...), $this->access->acceptableDepartmentIds($user)),
        };
    }
}
