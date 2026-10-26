<?php

namespace App\Policies;

use App\Enums\ConnectionChannel;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationStateGuard;

/**
 * Authorization of the conversation engine. Every ability combines the
 * permission of the action with the reach rules of ConversationAccess
 * (scope, departments and assignment). Read abilities (view, viewHistory,
 * supervise) use the read reach, widened by read-only supervision; every
 * mutating ability uses the operational reach (canOperate()). Personal,
 * non-operational items of the viewer (contact alias, chat appearance) only
 * require view.
 *
 * Each operation has two halves: permits() (permission and reach, whatever
 * the state: refused, it is a 403) and ConversationStateGuard (whether the
 * conversation as it is now admits it: refused, it is a 409). The abilities
 * combine both, for the interface and background checks; the engine checks
 * them apart, under lock (ConversationLifecycle::authorize()).
 */
class ConversationPolicy
{
    /** @var array<int, bool> */
    private array $templateChannels = [];

    public function __construct(
        private ConversationAccess $access,
        private ConversationStateGuard $state,
    ) {}

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
        return $this->permits($actor, 'accept', $conversation) && $this->state->allows($actor, 'accept', $conversation);
    }

    public function assign(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'assign', $conversation) && $this->state->allows($actor, 'assign', $conversation);
    }

    /**
     * The responsible agent transfers their own conversation; users who can
     * assign transfer any conversation they see.
     */
    public function transfer(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'transfer', $conversation) && $this->state->allows($actor, 'transfer', $conversation);
    }

    public function release(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'release', $conversation) && $this->state->allows($actor, 'release', $conversation);
    }

    public function close(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'close', $conversation) && $this->state->allows($actor, 'close', $conversation);
    }

    public function reopen(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'reopen', $conversation) && $this->state->allows($actor, 'reopen', $conversation);
    }

    public function addNote(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'addNote', $conversation);
    }

    /**
     * Tags classify the conversation: its responsible agent or whoever
     * manages conversations they see, with the tag assignment permission.
     */
    public function tag(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'tag', $conversation) && $this->state->allows($actor, 'tag', $conversation);
    }

    /**
     * Only the responsible agent writes to the customer, while attending.
     */
    public function reply(User $actor, Conversation $conversation): bool
    {
        return $this->permits($actor, 'reply', $conversation) && $this->state->allows($actor, 'reply', $conversation);
    }

    /**
     * Whether the actor holds the permission of the operation and reaches
     * the conversation operationally, whatever its current state. A refusal
     * here is a real authorization failure (403); with it granted, a state
     * that does not admit the operation is a conflict (409,
     * ConversationStateGuard). The abilities above combine both halves, so
     * the interface only offers what can be done right now. Notes are refused
     * on imported conversations too: they are follow-up of an attention, and
     * an imported conversation is a closed record that nobody attends.
     */
    public function permits(User $actor, string $ability, Conversation $conversation): bool
    {
        // Imported history is read-only for good: it is never accepted,
        // assigned, transferred, released, closed, reopened, tagged, noted
        // or answered (ConversationLifecycle::IMPORTED_MESSAGE).
        if ($conversation->isImported()) {
            return false;
        }

        return match ($ability) {
            'accept' => $this->permitsAccept($actor, $conversation),
            'assign' => $actor->holdsPermission(Permission::AssignConversations) && $this->operates($actor, $conversation),
            'transfer' => $actor->holdsPermission(Permission::TransferConversations) && $this->operates($actor, $conversation),
            'release' => ($actor->holdsPermission(Permission::ReplyConversations) || $actor->holdsPermission(Permission::AssignConversations))
                && $this->attendsOrOperates($actor, $conversation),
            'close' => $actor->holdsPermission(Permission::CloseConversations) && $this->attendsOrOperates($actor, $conversation),
            'reopen' => $actor->holdsPermission(Permission::ReopenConversations) && $this->operates($actor, $conversation),
            'addNote' => $actor->holdsPermission(Permission::AddConversationNotes) && $this->operates($actor, $conversation),
            'tag' => $actor->holdsPermission(Permission::AssignTags) && $this->operates($actor, $conversation),
            'reply' => $actor->holdsPermission(Permission::ReplyConversations) && $this->attendsOrOperates($actor, $conversation),
            'sendTemplate' => $actor->holdsPermission(Permission::SendTemplates) && $this->permits($actor, 'reply', $conversation)
                && $this->channelSupportsTemplates($conversation),
            default => false,
        };
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
        return $this->permits($actor, 'sendTemplate', $conversation) && $this->state->allows($actor, 'sendTemplate', $conversation);
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

    /**
     * The accept permission and a queue the actor may take conversations
     * from (conversations without a department, only global attendants).
     */
    private function permitsAccept(User $actor, Conversation $conversation): bool
    {
        if (! $actor->holdsPermission(Permission::AcceptConversations)) {
            return false;
        }

        return $conversation->department_id === null
            ? $this->access->acceptsUnroutedConversations($actor)
            : in_array($conversation->department_id, $this->access->acceptableDepartmentIds($actor), true);
    }

    /**
     * Reach of the operations the responsible agent performs on their own
     * conversation (reply, close, release): being its assignee is enough, as
     * it always was; anyone else needs the operational reach. Whether the
     * assignee still attends it (open, not closed) is the state guard's call.
     */
    private function attendsOrOperates(User $actor, Conversation $conversation): bool
    {
        return ($conversation->assigned_user_id !== null && $conversation->assigned_user_id === $actor->id)
            || $this->operates($actor, $conversation);
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
