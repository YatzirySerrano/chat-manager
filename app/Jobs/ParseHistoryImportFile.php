<?php

namespace App\Jobs;

use App\Models\HistoryImportFile;
use App\Services\Migration\History\HistoryFileIngestor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads one uploaded Whaticket file into the staging tables and rebuilds
 * the inventory of its batch (HistoryFileIngestor::parse). Reading errors
 * are kept on the file (shown to the admin), never thrown.
 *
 * Unique only until it starts, so a new column mapping chosen while the
 * file is being read is queued (the running reading notices the change and
 * discards its result; HistoryFileIngestor serializes both with a lock).
 * The timeout stays below the queue's retry_after, so a long file is never
 * handed to a second worker; a reading that dies anyway leaves the file
 * failed with «Reintentar», never "reading" forever.
 */
class ParseHistoryImportFile implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $fileId)
    {
        $this->timeout = self::timeout();
    }

    /**
     * Seconds a reading may take: up to 30 minutes, always 30 seconds below
     * the retry_after of the queue connection.
     */
    public static function timeout(): int
    {
        $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 900);

        return max(60, min(1800, ($retryAfter > 0 ? $retryAfter : 900) - 30));
    }

    public function uniqueId(): string
    {
        return 'history-import-file-'.$this->fileId;
    }

    public function handle(HistoryFileIngestor $ingestor): void
    {
        $file = HistoryImportFile::query()->find($this->fileId);

        if ($file === null) {
            return;
        }

        @ini_set('memory_limit', (string) config('exports.memory_limit', '1024M'));

        $ingestor->parse($file);
    }

    public function failed(?Throwable $exception): void
    {
        $file = HistoryImportFile::query()->find($this->fileId);

        if ($file !== null && in_array($file->status->value, ['uploaded', 'parsing'], true)) {
            app(HistoryFileIngestor::class)->failParse($file, $file->batch()->first(), 'La lectura del archivo se interrumpió (tardó demasiado o el servidor se reinició). Usa «Reintentar» para leerlo de nuevo.');
        }
    }
}
