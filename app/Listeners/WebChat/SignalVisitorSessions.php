<?php

namespace App\Listeners\WebChat;

use App\Enums\ConnectionChannel;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Events\Conversations\ConversationChanged;
use App\Events\Messaging\MessageRecorded;
use App\Events\Messaging\MessageStatusChanged;
use App\Events\WebChat\VisitorSessionSignal;
use App\Listeners\Realtime\QueuesRealtimeSignals;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorCursor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the visitor's open widgets that their web chat conversation
 * changed, so they refetch: an agent reply left (accepted) or was
 * delivered or read, the visitor wrote from another tab, or the
 * conversation was taken, transferred, reopened or closed. Every usable
 * session of the conversation's contact on that connection is signalled;
 * conversations of other channels are ignored, and the agents' listeners
 * are untouched. Delivery is best effort: the widget also polls.
 */
class SignalVisitorSessions implements ShouldQueue
{
    use QueuesRealtimeSignals;

    /** @var list<string> */
    private const MESSAGE_STATUSES = ['accepted', 'delivered', 'read'];

    /** @var list<ConversationEventType> */
    private const CONVERSATION_REASONS = [
        ConversationEventType::Accepted,
        ConversationEventType::Assigned,
        ConversationEventType::AutoAssigned,
        ConversationEventType::Transferred,
        ConversationEventType::Closed,
        ConversationEventType::Reopened,
    ];

    /** Sessions signalled per change (several tabs and devices). */
    private const MAX_SESSIONS = 20;

    public function __construct(private VisitorCursor $cursors) {}

    public function handleStatusChanged(MessageStatusChanged $event): void
    {
        if (! in_array($event->status, self::MESSAGE_STATUSES, true)) {
            return;
        }

        $this->signalMessage($event->messageId, $event->conversationId, MessageDirection::Outbound);
    }

    public function handleMessageRecorded(MessageRecorded $event): void
    {
        if ($event->direction !== MessageDirection::Inbound->value) {
            return;
        }

        $this->signalMessage($event->messageId, $event->conversationId, MessageDirection::Inbound);
    }

    public function handleConversationChanged(ConversationChanged $event): void
    {
        if (! in_array(ConversationEventType::tryFrom($event->reason), self::CONVERSATION_REASONS, true)) {
            return;
        }

        $conversation = $this->webChatConversation($event->conversationId);

        if ($conversation !== null) {
            $this->signal($conversation, 'conversation');
        }
    }

    private function signalMessage(int $messageId, int $conversationId, MessageDirection $direction): void
    {
        $conversation = $this->webChatConversation($conversationId);

        if ($conversation === null || ! Message::query()->whereKey($messageId)->where('direction', $direction)->exists()) {
            return;
        }

        $this->signal($conversation, 'messages', $this->cursors->encode($messageId));
    }

    private function webChatConversation(int $conversationId): ?Conversation
    {
        $conversation = Conversation::query()
            ->with('channelConnection:id,channel')
            ->find($conversationId, ['id', 'connection_id', 'contact_id']);

        return $conversation?->channelConnection?->channel === ConnectionChannel::WebChat ? $conversation : null;
    }

    /**
     * @param  'messages'|'conversation'  $type
     */
    private function signal(Conversation $conversation, string $type, ?string $cursor = null): void
    {
        $publicIds = WebChatSession::query()
            ->where('connection_id', $conversation->connection_id)
            ->where('contact_id', $conversation->contact_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->limit(self::MAX_SESSIONS)
            ->pluck('public_id')
            ->all();

        $channels = array_values(array_map(fn (string $publicId): string => 'webchat.sessions.'.$publicId, $publicIds));

        if ($channels === []) {
            return;
        }

        try {
            broadcast(new VisitorSessionSignal($channels, $type, $cursor));
        } catch (Throwable $exception) {
            Log::warning('No se pudo publicar una señal de tiempo real para el chat web; el widget se recuperará con la actualización periódica.', [
                'conversation_id' => $conversation->id,
                'sessions' => count($channels),
                'error' => $exception::class,
            ]);
        }
    }
}
