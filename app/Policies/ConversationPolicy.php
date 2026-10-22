<?php

namespace App\Policies;

use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;

/**
 * Authorization of the conversation engine. Every ability combines the
 * permission of the action with the visibility rules of ConversationAccess
 * (scope, departments and assignment). Whether the target state is valid
 * is checked again by the engine under lock.
 */
class ConversationPolicy
{
    public function __construct(private ConversationAccess $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewConversations);
    }

    public function view(User $actor, Conversation $conversation): bool
    {
        return $this->access->canView($actor, $conversation);
    }

    /**
     * The operational timeline (assignments, transfers, closings).
     */
    public function viewHistory(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::ViewConversationHistory) && $this->view($actor, $conversation);
    }

    public function accept(User $actor, Conversation $conversation): bool
    {
        return $this->access->canAccept($actor, $conversation);
    }

    public function assign(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::AssignConversations)
            && $this->view($actor, $conversation);
    }

    /**
     * The responsible agent transfers their own conversation; users who can
     * assign transfer any conversation they see.
     */
    public function transfer(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::TransferConversations)
            && $this->view($actor, $conversation)
            && ($this->isResponsible($actor, $conversation) || $actor->holdsPermission(Permission::AssignConversations));
    }

    public function release(User $actor, Conversation $conversation): bool
    {
        return $conversation->status->hasResponsible() && $this->attendsOrManages($actor, $conversation);
    }

    public function hold(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Open && $this->attendsOrManages($actor, $conversation);
    }

    public function resume(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::OnHold && $this->attendsOrManages($actor, $conversation);
    }

    public function close(User $actor, Conversation $conversation): bool
    {
        if ($conversation->status === ConversationStatus::Closed || ! $actor->holdsPermission(Permission::CloseConversations)) {
            return false;
        }

        return $this->isResponsible($actor, $conversation)
            || ($actor->holdsPermission(Permission::AssignConversations) && $this->view($actor, $conversation));
    }

    public function reopen(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Closed
            && $actor->holdsPermission(Permission::ReopenConversations)
            && $this->view($actor, $conversation);
    }

    public function addNote(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::AddConversationNotes) && $this->view($actor, $conversation);
    }

    /**
     * Tags classify the conversation: its responsible agent or whoever
     * manages conversations they see, with the tag assignment permission.
     */
    public function tag(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::AssignTags)
            && $this->view($actor, $conversation)
            && ($this->isResponsible($actor, $conversation) || $actor->holdsPermission(Permission::AssignConversations));
    }

    /**
     * Only the responsible agent writes to the customer, while attending.
     */
    public function reply(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Open
            && $this->isResponsible($actor, $conversation)
            && $actor->holdsPermission(Permission::ReplyConversations);
    }

    private function isResponsible(User $actor, Conversation $conversation): bool
    {
        return $conversation->status->hasResponsible() && $conversation->assigned_user_id === $actor->id;
    }

    private function attendsOrManages(User $actor, Conversation $conversation): bool
    {
        return ($this->isResponsible($actor, $conversation) && $actor->holdsPermission(Permission::ReplyConversations))
            || ($actor->holdsPermission(Permission::AssignConversations) && $this->view($actor, $conversation));
    }
}
