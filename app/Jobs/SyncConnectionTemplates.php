<?php

namespace App\Jobs;

use App\Actions\Templates\SyncMessageTemplates;
use App\Models\Connection;
use App\Services\Meta\MetaGraphException;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Synchronizes the templates of one connection in the background (scheduled,
 * after a status webhook about an unknown template, or on demand). Only one
 * synchronization per connection is queued at a time; temporary Meta
 * failures are retried with backoff, refusals are logged and stop.
 */
class SyncConnectionTemplates implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public int $uniqueFor = 600;

    public function __construct(public int $connectionId)
    {
        $this->onQueue(Queues::name(Queues::DEFAULT));
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(SyncMessageTemplates $sync): void
    {
        $connection = Connection::query()->find($this->connectionId);

        if ($connection === null || $connection->isDeleted() || ! $connection->hasRequiredCredentials()) {
            return;
        }

        try {
            $sync->handle($connection);
        } catch (MetaGraphException $exception) {
            if ($exception->isRetryable() || $exception->isAmbiguous()) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            Log::warning('WhatsApp templates could not be synchronized.', ['connection_id' => $connection->id, 'reason' => $exception->kind, 'meta_code' => $exception->metaCode]);
        }
    }
}
