<?php

namespace App\Policies;

use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;

/**
 * Authorization rules for the messaging module. Prepared in increment 01 so the
 * inbox can rely on them as soon as messaging is enabled. Every rule checks the
 * permission (what) and the data scope of the role (which conversations).
 */
class ConversationPolicy
{
    public function view(User $actor, Conversation $conversation): bool
    {
        if (! $actor->holdsPermission(Permission::ViewConversations)) {
            return false;
        }

        return match ($actor->dataScope()) {
            DataScope::Global => true,
            DataScope::Departments => $conversation->assigned_user_id === $actor->id
                || ($conversation->department_id !== null && $actor->belongsToDepartment($conversation->department_id)),
            default => $conversation->assigned_user_id === $actor->id,
        };
    }

    /**
     * Only the assigned user replies; supervisors must take the conversation first.
     */
    public function reply(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $conversation->assigned_user_id === $actor->id
            && $actor->holdsPermission(Permission::ReplyConversations);
    }

    public function assign(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::AssignConversations)
            && $this->view($actor, $conversation);
    }

    public function transfer(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::TransferConversations)
            && $this->view($actor, $conversation);
    }

    public function close(User $actor, Conversation $conversation): bool
    {
        if ($conversation->status === ConversationStatus::Closed || ! $actor->holdsPermission(Permission::CloseConversations)) {
            return false;
        }

        return $conversation->assigned_user_id === $actor->id
            || ($actor->holdsPermission(Permission::AssignConversations) && $this->view($actor, $conversation));
    }

    public function reopen(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Closed
            && $actor->holdsPermission(Permission::ReopenConversations)
            && $this->view($actor, $conversation);
    }
}
