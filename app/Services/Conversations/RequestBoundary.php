<?php

namespace App\Services\Conversations;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The read side of «Corregir tamaño de la solicitud» (the boundary between
 * two consecutive requests of the same contact; AdjustRequestBoundary moves
 * it):
 *
 * - previousOf(): the request right before a conversation, of the same
 *   contact AND the same connection (by creation, then id), absorbed ones
 *   left out. Imported history may be that request: then there is nothing
 *   to adjust (both must be live);
 * - canAdjust(): whether the viewer may move the boundary before a
 *   conversation (adjustBoundary on both requests);
 * - window(): what the dialog shows around the boundary, the last WINDOW
 *   messages of the earlier request and the first WINDOW of the later one,
 *   by id (the order the chat pages them in, and the order the boundary is
 *   cut in). Reactions are left out: they follow the message they react to.
 *
 * It only reads.
 */
class RequestBoundary
{
    /** Messages shown on each side of the boundary. */
    public const WINDOW = 30;

    /** Characters of a message's text shown in its row. */
    private const PREVIEW_LENGTH = 160;

    public function __construct(private RequestSummary $summary, private ProtectedAccounts $accounts) {}

    /**
     * The request right before the conversation (same contact and
     * connection), or null when it is the first one.
     */
    public function previousOf(Conversation $later): ?Conversation
    {
        if ($later->created_at === null) {
            return null;
        }

        return Conversation::query()
            ->where('contact_id', $later->contact_id)
            ->where('connection_id', $later->connection_id)
            ->whereKeyNot($later->id)
            ->whereNull('merged_at')
            ->where(fn (Builder $older) => $older
                ->where('created_at', '<', $later->created_at)
                ->orWhere(fn (Builder $same) => $same->where('created_at', $later->created_at)->where('id', '<', $later->id)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether the viewer may correct the boundary before this conversation.
     * Users who could never do it (no assign or supervise permission) are
     * answered without reading anything.
     */
    public function canAdjust(User $viewer, Conversation $later): bool
    {
        if ((! $viewer->holdsPermission(Permission::AssignConversations) && ! $viewer->holdsPermission(Permission::SuperviseConversations))
            || $later->isImported() || $later->isHistoryOnly() || $later->isMerged()) {
            return false;
        }

        $previous = $this->previousOf($later);

        return $previous !== null
            && ! $previous->isImported()
            && ! $previous->isHistoryOnly()
            && Gate::forUser($viewer)->allows('adjustBoundary', $later)
            && Gate::forUser($viewer)->allows('adjustBoundary', $previous);
    }

    /**
     * What the dialog shows: both requests (as RequestSummary tells them,
     * with their version) and the messages around the boundary, oldest
     * first, each saying which request it belongs to now.
     *
     * @return array{previous: array<string, mixed>, current: array<string, mixed>, messages: list<array<string, mixed>>, previous_total: int, current_total: int, previous_more: bool, current_more: bool}
     */
    public function window(Conversation $previous, Conversation $later, User $viewer): array
    {
        $previous->loadMissing(['department:id,name,color,is_active', 'assignee:id,name']);
        $later->loadMissing(['department:id,name,color,is_active', 'assignee:id,name']);

        $tail = $this->visibleMessages($previous->id)->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
        $head = $this->visibleMessages($later->id)->orderBy('id')->limit(self::WINDOW)->get();
        $previousTotal = $this->visibleMessages($previous->id)->count();
        $currentTotal = $this->visibleMessages($later->id)->count();

        $messages = $tail->concat($head)->sortBy('id')->values();
        (new Collection($messages->all()))->load(['sender:id,name', 'attachments:id,message_id,file_name,mime_type']);

        return [
            'previous' => [...$this->summary->of($previous, $viewer), 'version' => $previous->version],
            'current' => [...$this->summary->of($later, $viewer), 'version' => $later->version],
            'messages' => array_values($messages->map(fn (Message $message): array => $this->row($message, $viewer))->all()),
            'previous_total' => $previousTotal,
            'current_total' => $currentTotal,
            'previous_more' => $previousTotal > $tail->count(),
            'current_more' => $currentTotal > $head->count(),
        ];
    }

    /**
     * @return Builder<Message>
     */
    private function visibleMessages(int $conversationId): Builder
    {
        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('type', '!=', MessageType::Reaction);
    }

    /**
     * One compact row: when it was written, who wrote it and a preview.
     *
     * @return array<string, mixed>
     */
    private function row(Message $message, User $viewer): array
    {
        $body = trim((string) $message->body);
        $attachment = $message->attachments->first();

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'direction' => $message->direction->value,
            'type' => $message->type->value,
            'at' => $this->writtenAt($message)?->toIso8601String(),
            'sender' => $message->direction === MessageDirection::Outbound ? $this->accounts->nameFor($message->sender, $viewer) : null,
            'text' => $body === '' ? null : Str::limit(preg_replace('/\s+/u', ' ', $body) ?? $body, self::PREVIEW_LENGTH),
            'file_name' => $attachment?->file_name,
            'revoked' => $message->revoked_at !== null,
        ];
    }

    /**
     * As the chat orders it: a customer message by when the customer sent
     * it, anything else by when it was stored.
     */
    private function writtenAt(Message $message): ?CarbonInterface
    {
        return $message->direction === MessageDirection::Inbound
            ? ($message->sent_at ?? $message->created_at)
            : $message->created_at;
    }
}
