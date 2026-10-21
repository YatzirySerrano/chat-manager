<?php

namespace App\Jobs;

use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Meta\WhatsAppTransport;
use App\Services\Meta\WhatsAppTransportUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a queued agent reply. The customer service window is checked
 * again here, because the job may run long after the reply was queued:
 * when it expired meanwhile the message fails without reaching the
 * provider. A message is attempted once; it is never sent twice.
 */
class DeliverOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(CustomerServiceWindow $window, ConversationLifecycle $lifecycle, WhatsAppTransport $transport): void
    {
        $message = DB::transaction(function () use ($window, $lifecycle): ?Message {
            $message = Message::query()->lockForUpdate()->find($this->messageId);

            if ($message === null || $message->status !== MessageStatus::Queued || $message->direction !== MessageDirection::Outbound || $message->type !== MessageType::Text) {
                return null;
            }

            $conversation = Conversation::query()->findOrFail($message->conversation_id);
            $state = $window->for($conversation);

            if (! $state->allowsFreeFormMessages()) {
                $this->markFailed($message, 'customer_window_closed', $state->blockReason ?? 'La ventana de atención venció.');
                $lifecycle->record($conversation, ConversationEventType::OutboundBlocked, null, [
                    'metadata' => ['message_id' => $message->id, 'reason' => 'customer_window_closed'],
                ]);

                return null;
            }

            return $message;
        });

        if ($message === null) {
            return;
        }

        try {
            $providerId = $transport->sendText($message);
        } catch (WhatsAppTransportUnavailable $exception) {
            $this->markFailed($message, 'transport_unavailable', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            Log::error('Outbound WhatsApp message could not be delivered.', ['message_id' => $message->id, 'error' => $exception->getMessage()]);
            $this->markFailed($message, 'provider_error', 'El proveedor rechazó o no confirmó el envío.');

            return;
        }

        $message->forceFill([
            'whatsapp_message_id' => $providerId,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
        ])->save();
    }

    private function markFailed(Message $message, string $code, string $reason): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($reason, 0, 500),
            'failed_at' => now(),
        ])->save();
    }
}
