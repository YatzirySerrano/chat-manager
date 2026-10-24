<?php

namespace App\Actions\Messaging;

use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Messages left "sending" (the worker stopped, or Meta's answer never
 * arrived) and without any status webhook after the configured minutes are
 * marked failed with code "delivery_unknown". They are never re-sent
 * automatically: Meta may have delivered them, so an agent decides (and
 * confirms the duplicate risk) through RetryOutboundMessage. A status
 * webhook arriving later still reconciles them (RecordMessageStatus).
 *
 * Web chat replies are the exception: their outcome is never ambiguous
 * (the visitor only sees accepted replies), so they are queued again.
 */
class ReconcileStuckDeliveries
{
    public const CODE = 'delivery_unknown';

    /** Web chat reply that kept stalling: its outcome is known (not seen). */
    public const WEB_CHAT_CODE = 'delivery_error';

    public function __construct(private ConversationLifecycle $lifecycle) {}

    public function handle(): int
    {
        $minutes = max(1, (int) config('whatsapp.delivery.reconcile_after_minutes', 15));
        $count = 0;

        Message::query()
            ->where('status', MessageStatus::Sending)
            ->where('sending_started_at', '<', now()->subMinutes($minutes))
            ->select('id')
            ->chunkById(100, function ($messages) use (&$count, $minutes): void {
                foreach ($messages as $stale) {
                    $count += $this->reconcile($stale->id, $minutes) ? 1 : 0;
                }
            });

        return $count;
    }

    private function reconcile(int $messageId, int $minutes): bool
    {
        return DB::transaction(function () use ($messageId, $minutes): bool {
            $message = Message::query()->lockForUpdate()->find($messageId);

            if ($message === null || $message->status !== MessageStatus::Sending
                || $message->sending_started_at === null || $message->sending_started_at->greaterThanOrEqualTo(now()->subMinutes($minutes))) {
                return false;
            }

            $conversation = Conversation::query()->with('channelConnection')->findOrFail($message->conversation_id);

            if ($conversation->channelConnection?->isWebChat() ?? false) {
                return $this->reconcileWebChat($message, $conversation);
            }

            $message->forceFill([
                'status' => MessageStatus::Failed,
                'error_code' => self::CODE,
                'error_message' => 'No se pudo confirmar si WhatsApp recibió el mensaje. Verifica con el cliente antes de reintentarlo: podría recibirlo dos veces.',
                'failed_at' => now(),
            ])->save();

            $this->lifecycle->record($conversation, ConversationEventType::MessageFailed, null, [
                'metadata' => ['message_id' => $message->id, 'reason' => self::CODE],
            ]);
            MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Failed->value, MessageStatus::Sending->value);

            return true;
        });
    }

    /**
     * A web chat reply never has an ambiguous outcome: the visitor only sees
     * it once accepted, and the web transport does not leave the server. It
     * is queued again (the delivery job checks everything again) while
     * attempts remain, and otherwise fails without any duplicate warning.
     */
    private function reconcileWebChat(Message $message, Conversation $conversation): bool
    {
        if (DeliverOutboundMessage::attemptsInCycle($message) < DeliverOutboundMessage::maxAttempts()) {
            $message->forceFill([
                'status' => MessageStatus::Queued,
                'sending_started_at' => null,
            ])->save();

            DeliverOutboundMessage::dispatch($message->id)->afterCommit();
            MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Queued->value, MessageStatus::Sending->value);

            return true;
        }

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error_code' => self::WEB_CHAT_CODE,
            'error_message' => 'No se pudo completar el envío por el chat web; el visitante no recibió el mensaje. Puedes reintentarlo sin riesgo de duplicado.',
            'failed_at' => now(),
        ])->save();

        $this->lifecycle->record($conversation, ConversationEventType::MessageFailed, null, [
            'metadata' => ['message_id' => $message->id, 'reason' => self::WEB_CHAT_CODE],
        ]);
        MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Failed->value, MessageStatus::Sending->value);

        return true;
    }
}
