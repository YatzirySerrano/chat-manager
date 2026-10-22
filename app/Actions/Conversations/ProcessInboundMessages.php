<?php

namespace App\Actions\Conversations;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\MetaInboundPayload;
use App\Support\InboundMessageReceipt;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for the future Meta webhook consumer: receives every customer
 * message of an authenticated webhook body. Re-processing the same body is
 * harmless, so a failed delivery or job can simply be retried.
 */
class ProcessInboundMessages
{
    public function __construct(private ReceiveInboundMessage $receiveInboundMessage) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<InboundMessageReceipt>
     */
    public function handle(array $payload): array
    {
        $receipts = [];
        $connections = [];

        foreach (MetaInboundPayload::messages($payload) as $message) {
            $connections[$message->phoneNumberId] ??= Connection::query()
                ->where('channel', ConnectionChannel::Whatsapp)
                ->where('phone_number_id', $message->phoneNumberId)
                ->first();

            if ($connections[$message->phoneNumberId] === null) {
                Log::warning('Inbound message ignored: no connection uses this phone number id.', [
                    'phone_number_id' => $message->phoneNumberId,
                    'message_id' => $message->sender->messageId,
                ]);

                continue;
            }

            // A deleted connection no longer operates: its history is kept,
            // but new traffic is not attached to it.
            if ($connections[$message->phoneNumberId]->isDeleted()) {
                Log::warning('Inbound message ignored: the connection of this phone number id was deleted.', [
                    'connection_id' => $connections[$message->phoneNumberId]->id,
                    'message_id' => $message->sender->messageId,
                ]);

                continue;
            }

            $receipts[] = $this->receiveInboundMessage->handle($connections[$message->phoneNumberId], $message);
        }

        return $receipts;
    }
}
