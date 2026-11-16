<?php

namespace App\Jobs;

use App\Services\Storage\MediaThumbnails;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Makes the small preview of an image as soon as the file is stored, on the
 * media queue, so the first person who opens the chat does not wait for the
 * original to be decoded and reduced inside their request. Only a speed-up:
 * the attachment routes still make the preview on demand when it is missing
 * (MediaThumbnails::ensure is idempotent), and any failure is ignored.
 */
class WarmMediaThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  string|null  $dimensionsTable  Table whose row keeps the measured size (message_attachments).
     */
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
        public ?int $sizeBytes = null,
        public ?string $dimensionsTable = null,
        public ?int $attachmentId = null,
    ) {
        $this->onQueue(Queues::name(Queues::MEDIA));
    }

    /**
     * Queues the warm-up after the surrounding transaction commits, only for
     * images a preview can be made of.
     */
    public static function warm(string $disk, ?string $path, string $mime, ?int $sizeBytes, ?string $dimensionsTable = null, ?int $attachmentId = null): void
    {
        if ($path === null || $path === '' || ! app(MediaThumbnails::class)->supports($mime, $sizeBytes)) {
            return;
        }

        self::dispatch($disk, $path, $mime, $sizeBytes, $dimensionsTable, $attachmentId)->afterCommit();
    }

    public function handle(MediaThumbnails $thumbnails): void
    {
        $thumbnail = $thumbnails->ensure($this->disk, $this->path, strtolower($this->mime), $this->sizeBytes);

        // The layout hint the chat uses to reserve the image's box.
        if ($thumbnail !== null && $this->dimensionsTable !== null && $this->attachmentId !== null && $thumbnail['width'] !== null) {
            DB::table($this->dimensionsTable)
                ->where('id', $this->attachmentId)
                ->whereNull('width')
                ->update(['width' => $thumbnail['width'], 'height' => $thumbnail['height']]);
        }
    }
}
