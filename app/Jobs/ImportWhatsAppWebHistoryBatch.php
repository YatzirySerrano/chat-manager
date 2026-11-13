<?php

namespace App\Jobs;

use App\Actions\WhatsAppWeb\ImportWhatsAppWebHistory;
use App\Models\Connection;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One slice of the chat history of a number linked by QR ("history.batch"
 * event of the WhatsApp Web service), stored by ImportWhatsAppWebHistory.
 *
 * The internal endpoint only queues it, so a long history never holds the
 * service's event delivery (live messages travel on the same ordered
 * channel) nor a web request. It runs on the default queue, below webhooks,
 * outbound messages, real-time signals, notifications and media, so the
 * import of a large history never delays the attention of live chats.
 *
 * The payload carries message texts and the keys to download their files:
 * it is encrypted while it waits in the queue. Storing is idempotent (the
 * message id is unique), so a retry or a repeated slice changes nothing.
 */
class ImportWhatsAppWebHistoryBatch implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 110;

    /**
     * @param  list<array<array-key, mixed>>  $messages  InboundMessage payloads (contract §5).
     */
    public function __construct(public int $connectionId, public array $messages)
    {
        $this->onQueue(Queues::name(Queues::DEFAULT));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function handle(ImportWhatsAppWebHistory $import): void
    {
        $connection = Connection::query()->find($this->connectionId);

        // A connection deleted meanwhile receives nothing (its history is kept).
        if ($connection === null || $connection->isDeleted() || ! $connection->isWhatsAppWeb()) {
            return;
        }

        $result = $import->handle($connection, $this->messages);

        // Counts only: never a text, a number or a name.
        Log::info('WhatsApp Web history slice imported.', ['connection_id' => $connection->id, ...$result]);
    }
}
