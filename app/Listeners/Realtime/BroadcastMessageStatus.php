<?php

namespace App\Listeners\Realtime;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Events\Messaging\MessageStatusChanged;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Delivery progress of a message (accepted, sent, delivered, read, failed)
 * reaches whoever looks at the conversation; a failure also reaches the
 * responsible agent wherever they are, so it never goes unnoticed.
 *
 * When the message is the conversation's latest one, the inbox lists that
 * show it also get a lightweight `inbox.message_status` (ids and state, no
 * content), so the status icon of the row follows the thread without
 * reloading the list. Older messages never reach the lists: their rows do
 * not show them. Statuses only move forward on the server (RecordMessageStatus
 * and DeliverOutboundMessage guard it), and clients apply an update only
 * when it advances the state they show (docs/tiempo-real.md).
 */
class BroadcastMessageStatus implements ShouldQueue
{
    use QueuesRealtimeSignals;

    public function __construct(private RealtimeSignals $signals) {}

    public function handle(MessageStatusChanged $event): void
    {
        $conversation = Conversation::query()->find($event->conversationId);

        if ($conversation === null) {
            return;
        }

        $message = Message::query()
            ->whereKey($event->messageId)
            ->where('conversation_id', $conversation->id)
            ->first(['id', 'conversation_id', 'status', 'error_code', 'updated_at']);

        // The failure code only describes the stored failure (for example
        // delivery_unknown), never a status the message already left.
        $errorCode = $event->status === 'failed' && $message?->status->value === 'failed' ? $message->error_code : null;
        $updatedAt = $message?->updated_at?->toIso8601String();

        $this->signals->send([RealtimeChannels::conversation($conversation->id)], 'message.status', [
            'message_id' => $event->messageId,
            'status' => $event->status,
            'error_code' => $errorCode,
            'updated_at' => $updatedAt,
        ]);

        if ($message !== null && $this->isLatest($message)) {
            $this->signals->inboxMessageStatus($conversation, [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'status' => $event->status,
                'error_code' => $errorCode,
                'updated_at' => $updatedAt,
            ]);
        }

        if ($event->status === 'failed') {
            $this->signals->toUsers([$conversation->assigned_user_id], 'inbox.changed', [
                'conversation_id' => $conversation->id,
                'reason' => 'message_failed',
            ]);
        }
    }

    /**
     * Whether no later message exists in the conversation: one lookup on
     * the messages(conversation_id, id) index.
     */
    private function isLatest(Message $message): bool
    {
        return ! Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('id', '>', $message->id)
            ->exists();
    }
}
