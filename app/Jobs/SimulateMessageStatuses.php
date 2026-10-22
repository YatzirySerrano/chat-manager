<?php

namespace App\Jobs;

use App\Actions\Messaging\RecordMessageStatus;
use App\Models\Message;
use App\Services\Meta\MetaCloudWhatsAppTransport;
use App\Services\Meta\ProviderStatus;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SIMULACIÓN — local development only. Reports sent → delivered (→ read)
 * for a message accepted by the simulated transport, through the same
 * status processing used for Meta's webhooks. Never runs in production.
 */
class SimulateMessageStatuses implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $messageId, public string $providerMessageId)
    {
        $this->onQueue(Queues::name(Queues::WEBHOOKS));
    }

    public function handle(RecordMessageStatus $recordStatus): void
    {
        if (app()->isProduction()) {
            return;
        }

        $message = Message::query()->with('conversation.channelConnection')->find($this->messageId);
        $connection = $message?->conversation->channelConnection;

        if ($message === null || $connection === null) {
            return;
        }

        $statuses = ['sent', 'delivered'];

        if (config('whatsapp.simulation.simulate_read')) {
            $statuses[] = 'read';
        }

        foreach ($statuses as $offset => $status) {
            $recordStatus->handle($connection, new ProviderStatus(
                phoneNumberId: (string) $connection->phone_number_id,
                messageId: $this->providerMessageId,
                status: $status,
                occurredAt: CarbonImmutable::now()->addSeconds($offset),
                callbackData: MetaCloudWhatsAppTransport::callbackData($message),
            ));
        }
    }
}
