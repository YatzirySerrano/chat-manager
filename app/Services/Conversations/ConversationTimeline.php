<?php

namespace App\Services\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\MessageType;
use App\Http\Resources\ConversationEventResource;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationNoteResource;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationNote;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The chat of a conversation as the inbox renders it: customer messages and
 * replies, interleaved with internal notes and (with the history
 * permission) the operational events. Messages are paged by id, newest
 * first, with a stable cursor; notes and events travel with the page of
 * messages whose time span contains them, so every item appears exactly
 * once whichever way the chat is scrolled.
 *
 * Callers must have authorized the viewer to see the conversation.
 *
 * @phpstan-type TimelinePage array{messages: list<array<string, mixed>>, notes: list<array<string, mixed>>, events: list<array<string, mixed>>, has_more: bool}
 */
class ConversationTimeline
{
    /** Messages per page. */
    public const PAGE_SIZE = 40;

    /** Upper bound of new items returned by one "after" request per stream. */
    public const AFTER_LIMIT = 200;

    /** Upper bound of notes or events returned with one page. */
    private const SIDE_LIMIT = 300;

    /**
     * Operational events shown inside the chat (notes are shown themselves).
     *
     * @var list<ConversationEventType>
     */
    private const HIDDEN_EVENTS = [ConversationEventType::NoteAdded, ConversationEventType::NoteEdited];

    /**
     * The newest page, or the page right before the given message.
     *
     * @return TimelinePage
     */
    public function page(Conversation $conversation, Request $request, ?int $beforeMessageId = null): array
    {
        $upper = null;

        if ($beforeMessageId !== null) {
            $cursor = Message::query()->where('conversation_id', $conversation->id)->whereKey($beforeMessageId)->first(['id', 'created_at']);

            if ($cursor === null) {
                return ['messages' => [], 'notes' => [], 'events' => [], 'has_more' => false];
            }

            $upper = $cursor->created_at;
        }

        $messages = $this->messages($conversation)
            ->when($beforeMessageId !== null, fn (Builder $query) => $query->where('id', '<', $beforeMessageId))
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $messages->count() > self::PAGE_SIZE;
        $messages = $messages->take(self::PAGE_SIZE)->reverse()->values();
        $lower = $hasMore ? $messages->first()?->created_at : null;

        $within = fn (Builder $query) => $query
            ->when($lower !== null, fn (Builder $range) => $range->where('created_at', '>=', $lower))
            ->when($upper !== null, fn (Builder $range) => $range->where('created_at', '<', $upper))
            ->orderByDesc('id')
            ->limit(self::SIDE_LIMIT);

        return [
            'messages' => $this->presentMessages($conversation, $messages, $request),
            'notes' => $this->presentNotes($this->notes($conversation)->tap($within)->get()->reverse()->values(), $request),
            'events' => $this->canSeeEvents($conversation, $request)
                ? $this->presentEvents($this->events($conversation)->tap($within)->get()->reverse()->values(), $request)
                : [],
            'has_more' => $hasMore,
        ];
    }

    /**
     * Items stored after the ones the chat already has (real-time catch up).
     *
     * @return TimelinePage
     */
    public function after(Conversation $conversation, Request $request, int $afterMessageId, int $afterNoteId, int $afterEventId): array
    {
        $messages = $this->messages($conversation)->where('id', '>', $afterMessageId)->orderBy('id')->limit(self::AFTER_LIMIT)->get();

        // A chat that holds no note (or event) yet sends 0 for that stream.
        // Notes and events older than its newest known message belong to
        // pages it has not loaded: they arrive with those pages instead.
        $since = $afterMessageId > 0
            ? Message::query()->where('conversation_id', $conversation->id)->whereKey($afterMessageId)->value('created_at')
            : null;
        $notes = $this->notes($conversation)->where('id', '>', $afterNoteId);
        $events = $this->events($conversation)->where('id', '>', $afterEventId);

        if ($since !== null && $afterNoteId === 0) {
            $notes->where('created_at', '>=', $since);
        }

        if ($since !== null && $afterEventId === 0) {
            $events->where('created_at', '>=', $since);
        }

        return [
            'messages' => $this->presentMessages($conversation, $messages, $request),
            'notes' => $this->presentNotes($notes->orderBy('id')->limit(self::AFTER_LIMIT)->get(), $request),
            'events' => $this->canSeeEvents($conversation, $request)
                ? $this->presentEvents($events->orderBy('id')->limit(self::AFTER_LIMIT)->get(), $request)
                : [],
            'has_more' => false,
        ];
    }

    /**
     * Current state of items the chat already shows (delivery status
     * changes, edited notes). Ids of other conversations are ignored.
     *
     * @param  list<int>  $messageIds
     * @param  list<int>  $noteIds
     * @return TimelinePage
     */
    public function refresh(Conversation $conversation, Request $request, array $messageIds, array $noteIds): array
    {
        $messages = $messageIds === [] ? new Collection : $this->messages($conversation)->whereKey($messageIds)->orderBy('id')->get();
        $notes = $noteIds === [] ? new Collection : $this->notes($conversation)->whereKey($noteIds)->orderBy('id')->get();

        return [
            'messages' => $this->presentMessages($conversation, $messages, $request),
            'notes' => $this->presentNotes($notes, $request),
            'events' => [],
            'has_more' => false,
        ];
    }

    /**
     * @return Builder<Message>
     */
    private function messages(Conversation $conversation): Builder
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender', 'attachments', 'replyTo']);
    }

    /**
     * @return Builder<ConversationNote>
     */
    private function notes(Conversation $conversation): Builder
    {
        return ConversationNote::query()->where('conversation_id', $conversation->id)->with('author');
    }

    /**
     * @return Builder<ConversationEvent>
     */
    private function events(Conversation $conversation): Builder
    {
        return ConversationEvent::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotIn('type', self::HIDDEN_EVENTS)
            ->with(['actor', 'fromUser', 'toUser', 'fromDepartment', 'toDepartment']);
    }

    private function canSeeEvents(Conversation $conversation, Request $request): bool
    {
        return Gate::forUser($request->user())->allows('viewHistory', $conversation);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function presentMessages(Conversation $conversation, Collection $messages, Request $request): array
    {
        $this->resolveReactions($conversation, $messages);

        return array_values(ConversationMessageResource::collection($messages)->resolve($request));
    }

    /**
     * @param  Collection<int, ConversationNote>  $notes
     * @return list<array<string, mixed>>
     */
    private function presentNotes(Collection $notes, Request $request): array
    {
        return array_values(ConversationNoteResource::collection($notes)->resolve($request));
    }

    /**
     * @param  Collection<int, ConversationEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function presentEvents(Collection $events, Request $request): array
    {
        return array_values(ConversationEventResource::collection($events)->resolve($request));
    }

    /**
     * Reactions name the provider id of the message they react to; the chat
     * needs the local one. Resolved in one query per page.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function resolveReactions(Conversation $conversation, Collection $messages): void
    {
        $targets = $messages
            ->filter(fn (Message $message): bool => $message->type === MessageType::Reaction)
            ->mapWithKeys(function (Message $message): array {
                $content = is_array($message->metadata) ? ($message->metadata['content'] ?? null) : null;
                $target = is_array($content) ? ($content['message_id'] ?? null) : null;

                return is_string($target) ? [$message->id => $target] : [];
            });

        if ($targets->isEmpty()) {
            return;
        }

        $found = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('whatsapp_message_id', $targets->values()->unique()->all())
            ->get(['id', 'whatsapp_message_id'])
            ->keyBy('whatsapp_message_id');

        $messages->each(function (Message $message) use ($targets, $found): void {
            if ($targets->has($message->id)) {
                $message->setRelation('reactionTarget', $found->get($targets->get($message->id)));
            }
        });
    }
}
