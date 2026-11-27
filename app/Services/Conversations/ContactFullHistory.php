<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * «Conversación completa»: everything the contact wrote and was answered,
 * across all their conversations the viewer may see, in chronological order
 * and not cut by request. Each conversation is one segment, told as the
 * request it was (RequestSummary), so the chat marks where each request
 * starts.
 *
 * Read backwards a page at a time, newest first: a page holds at most
 * PAGE_SIZE messages and SEGMENTS_MAX conversations, and says where the
 * next older page continues (`next`: a conversation and, when the page ended
 * inside it, the message it stopped at). Conversations follow each other by
 * creation (then id); inside one, messages are delimited as in its own chat
 * (ConversationTimeline::historySlice()), so nothing is left out or repeated
 * whichever conversation a page ends in.
 *
 * Only conversations the viewer may open are read (the same rule as the
 * chat, its timeline and its files: ConversationAccess::restrictToVisible()),
 * so history archived as read-only stays in its own archive. A cursor that
 * names a conversation outside that reach reads as an empty page. It only
 * reads: no read marker, counter or notification changes.
 *
 * @phpstan-type Cursor array{conversation: int, message: int|null}
 */
class ContactFullHistory
{
    /** Messages per page. */
    public const PAGE_SIZE = 40;

    /** Conversations per page at most (empty ones still count as a segment). */
    public const SEGMENTS_MAX = 15;

    public function __construct(private ConversationTimeline $timeline, private RequestSummary $summary) {}

    /**
     * The newest page of the contact's history, or the one the cursor names.
     *
     * @return array{segments: list<array{conversation: array<string, mixed>, messages: list<array<string, mixed>>, starts: bool}>, next: Cursor|null}
     */
    public function page(Conversation $anchor, User $viewer, Request $request, ?int $beforeConversationId = null, ?int $beforeMessageId = null): array
    {
        $conversations = $this->conversations($anchor, $viewer, $beforeConversationId);

        if ($conversations === null) {
            return ['segments' => [], 'next' => null];
        }

        $remaining = self::PAGE_SIZE;
        $slices = [];
        $next = null;

        foreach ($conversations->values() as $index => $conversation) {
            if ($index >= self::SEGMENTS_MAX || $remaining === 0) {
                $next = ['conversation' => $conversation->id, 'message' => null];

                break;
            }

            $slice = $this->timeline->historySlice($conversation, $index === 0 ? $beforeMessageId : null, $remaining);
            $slices[] = [$conversation, $slice];
            $remaining -= $slice['messages']->count();

            if ($slice['has_more']) {
                // The slice is newest first: its last message is where the next page continues.
                $next = ['conversation' => $conversation->id, 'message' => $slice['messages']->last()?->id];

                break;
            }
        }

        // Every message of the page gets its relations in one go, whatever its conversation.
        $all = new Collection;

        foreach ($slices as [, $slice]) {
            foreach ($slice['messages'] as $message) {
                $all->push($message);
            }
        }

        $all->load(['sender', 'attachments', 'replyTo']);

        $segments = [];

        foreach (array_reverse($slices) as [$conversation, $slice]) {
            /** @var Conversation $conversation */
            /** @var Collection<int, Message> $messages */
            $messages = $slice['messages'];
            $segments[] = [
                'conversation' => [...$this->summary->of($conversation, $viewer), 'is_current' => $conversation->id === $anchor->id],
                'messages' => $this->timeline->presentHistory($conversation, $messages, $request),
                // The page reached the first message of the conversation: its separator goes on top.
                'starts' => ! $slice['has_more'],
            ];
        }

        return ['segments' => $segments, 'next' => $next];
    }

    /**
     * The contact's visible conversations from the cursor back, newest
     * first (one more than a page may hold, to know whether more follow),
     * or null when the cursor names one outside the viewer's reach.
     *
     * @return Collection<int, Conversation>|null
     */
    private function conversations(Conversation $anchor, User $viewer, ?int $beforeConversationId): ?Collection
    {
        $visible = fn (): Builder => Conversation::query()
            ->where('contact_id', $anchor->contact_id)
            ->visibleTo($viewer);

        $from = null;

        if ($beforeConversationId !== null) {
            $from = $visible()->whereKey($beforeConversationId)->first(['id', 'created_at']);

            if ($from === null) {
                return null;
            }
        }

        return $visible()
            ->when($from !== null, fn (Builder $query) => $query->where(fn (Builder $older) => $older
                ->where('created_at', '<', $from->created_at)
                ->orWhere(fn (Builder $same) => $same->where('created_at', $from->created_at)->where('id', '<=', $from->id))))
            ->with(['department:id,name,color,is_active', 'assignee:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SEGMENTS_MAX + 1)
            ->get();
    }
}
