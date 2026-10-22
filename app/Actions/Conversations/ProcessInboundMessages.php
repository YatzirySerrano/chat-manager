<?php

namespace App\Actions\Conversations;

use App\Enums\ConnectionChannel;
use App\Enums\InboundMessageOutcome;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaInboundPayload;
use App\Support\InboundMessageReceipt;
use Illuminate\Support\Facades\Log;

/**
 * Receives every customer message of an authenticated webhook body.
 * Re-processing the same body is harmless, so a failed delivery or job can
 * simply be retried.
 *
 * When the body arrived through a connection's own webhook URL, only the
 * messages addressed to that connection's phone number are accepted: the
 * payload's phone number id is never trusted to pick another connection.
 *
 * Connection policy: a deleted connection receives nothing (its history is
 * kept). A deactivated (not deleted) connection still stores customer
 * messages so none are lost while an administrator pauses it; sending
 * through it stays blocked (DeliverOutboundMessage).
 */
class ProcessInboundMessages
{
    public function __construct(
        private ReceiveInboundMessage $receiveInboundMessage,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<InboundMessageReceipt>
     */
    public function handle(array $payload, ?Connection $through = null): array
    {
        $receipts = [];
        $connections = [];

        foreach (MetaInboundPayload::messages($payload) as $message) {
            if ($through !== null && $message->phoneNumberId !== $through->phone_number_id) {
                Log::warning('Inbound message ignored: it is addressed to another phone number than the webhook connection.', [
                    'connection_id' => $through->id,
                    'message_id' => $message->sender->messageId,
                ]);

                continue;
            }

            $connections[$message->phoneNumberId] ??= $through ?? Connection::query()
                ->where('channel', ConnectionChannel::Whatsapp)
                ->where('phone_number_id', $message->phoneNumberId)
                ->first();

            $connection = $connections[$message->phoneNumberId];

            if ($connection === null) {
                Log::warning('Inbound message ignored: no connection uses this phone number id.', [
                    'phone_number_id' => $message->phoneNumberId,
                    'message_id' => $message->sender->messageId,
                ]);

                continue;
            }

            // A deleted connection no longer operates: its history is kept,
            // but new traffic is not attached to it.
            if ($connection->isDeleted()) {
                Log::warning('Inbound message ignored: the connection of this phone number id was deleted.', [
                    'connection_id' => $connection->id,
                    'message_id' => $message->sender->messageId,
                ]);

                continue;
            }

            $receipt = $this->receiveInboundMessage->handle($connection, $message);
            $receipts[] = $receipt;

            if ($receipt->outcome !== InboundMessageOutcome::Duplicate) {
                $this->monitor->inboundReceived($connection, $message->sender->occurredAt);
            }
        }

        return $receipts;
    }
}
