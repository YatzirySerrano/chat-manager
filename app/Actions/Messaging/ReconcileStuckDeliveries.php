<?php

namespace App\Actions\Messaging;

use App\Enums\ConversationEventType;
use App\Enums\MessageStatus;
use App\Events\Messaging\MessageStatusChanged;
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
 */
class ReconcileStuckDeliveries
{
    public const CODE = 'delivery_unknown';

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

            $message->forceFill([
                'status' => MessageStatus::Failed,
                'error_code' => self::CODE,
                'error_message' => 'No se pudo confirmar si WhatsApp recibió el mensaje. Verifica con el cliente antes de reintentarlo: podría recibirlo dos veces.',
                'failed_at' => now(),
            ])->save();

            $this->lifecycle->record(Conversation::query()->findOrFail($message->conversation_id), ConversationEventType::MessageFailed, null, [
                'metadata' => ['message_id' => $message->id, 'reason' => self::CODE],
            ]);
            MessageStatusChanged::dispatch($message->id, $message->conversation_id, MessageStatus::Failed->value, MessageStatus::Sending->value);

            return true;
        });
    }
}
