<?php

namespace App\Policies;

use App\Enums\ConnectionChannel;
use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;

/**
 * Authorization of the conversation engine. Every ability combines the
 * permission of the action with the reach rules of ConversationAccess
 * (scope, departments and assignment). Read abilities (view, viewHistory,
 * supervise) use the read reach, widened by read-only supervision; every
 * mutating ability uses the operational reach (canOperate()). Personal,
 * non-operational items of the viewer (contact alias, chat appearance) only
 * require view. Whether the target state is valid is checked again by the
 * engine under lock, through these same abilities.
 */
class ConversationPolicy
{
    /** @var array<int, bool> */
    private array $templateChannels = [];

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
     * The operational timeline (assignments, transfers, closings): with the
     * history permission, or supervising (read-only) the viewer's reach.
     */
    public function viewHistory(User $actor, Conversation $conversation): bool
    {
        return $this->access->readsClosedConversations($actor) && $this->view($actor, $conversation);
    }

    public function accept(User $actor, Conversation $conversation): bool
    {
        return $this->access->canAccept($actor, $conversation);
    }

    public function assign(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::AssignConversations)
            && $this->operates($actor, $conversation);
    }

    /**
     * The responsible agent transfers their own conversation; users who can
     * assign transfer any conversation they see.
     */
    public function transfer(User $actor, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed
            && $actor->holdsPermission(Permission::TransferConversations)
            && $this->operates($actor, $conversation)
            && ($this->isResponsible($actor, $conversation) || $actor->holdsPermission(Permission::AssignConversations));
    }

    public function release(User $actor, Conversation $conversation): bool
    {
        return $conversation->status->hasResponsible() && $this->attendsOrManages($actor, $conversation);
    }

    public function close(User $actor, Conversation $conversation): bool
    {
        if ($conversation->status === ConversationStatus::Closed || ! $actor->holdsPermission(Permission::CloseConversations)) {
            return false;
        }

        return $this->isResponsible($actor, $conversation)
            || ($actor->holdsPermission(Permission::AssignConversations) && $this->operates($actor, $conversation));
    }

    public function reopen(User $actor, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Closed
            && $actor->holdsPermission(Permission::ReopenConversations)
            && $this->operates($actor, $conversation);
    }

    public function addNote(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::AddConversationNotes) && $this->operates($actor, $conversation);
    }

    /**
     * Tags classify the conversation: its responsible agent or whoever
     * manages conversations they see, with the tag assignment permission.
     */
    public function tag(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::AssignTags)
            && $this->operates($actor, $conversation)
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

    /**
     * Read-only supervision within the viewer's reach: it widens what they
     * see (ConversationAccess::canView()) and the operational details shown,
     * never what they may do. Every operation checks its own permission.
     */
    public function supervise(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::SuperviseConversations) && $this->view($actor, $conversation);
    }

    /**
     * WhatsApp templates are an administrative send: on top of being able to
     * reply, the dedicated (global only) permission is required.
     */
    public function sendTemplate(User $actor, Conversation $conversation): bool
    {
        return $actor->holdsPermission(Permission::SendTemplates) && $this->reply($actor, $conversation)
            && $this->channelSupportsTemplates($conversation);
    }

    /**
     * Only WhatsApp has templates: they never open a Messenger, Instagram or
     * web chat conversation. The channel comes from the loaded connection or
     * one memoized query per connection (it never changes).
     */
    private function channelSupportsTemplates(Conversation $conversation): bool
    {
        if ($conversation->relationLoaded('channelConnection') && $conversation->channelConnection !== null
            && array_key_exists('channel', $conversation->channelConnection->getAttributes())) {
            return $conversation->channelConnection->channel->supportsTemplates();
        }

        $connectionId = (int) $conversation->connection_id;

        if (! array_key_exists($connectionId, $this->templateChannels)) {
            $value = Connection::query()->whereKey($connectionId)->toBase()->value('channel');
            $this->templateChannels[$connectionId] = is_string($value) && (ConnectionChannel::tryFrom($value)?->supportsTemplates() ?? false);
        }

        return $this->templateChannels[$connectionId];
    }

    private function isResponsible(User $actor, Conversation $conversation): bool
    {
        return $conversation->status->hasResponsible() && $conversation->assigned_user_id === $actor->id;
    }

    private function attendsOrManages(User $actor, Conversation $conversation): bool
    {
        return ($this->isResponsible($actor, $conversation) && $actor->holdsPermission(Permission::ReplyConversations))
            || ($actor->holdsPermission(Permission::AssignConversations) && $this->operates($actor, $conversation));
    }

    /**
     * Operational reach for every mutating ability: the read-only
     * supervision widening (closed without history, passage through the
     * supervisor's departments) never lets anyone act on a conversation.
     */
    private function operates(User $actor, Conversation $conversation): bool
    {
        return $this->access->canOperate($actor, $conversation);
    }
}
