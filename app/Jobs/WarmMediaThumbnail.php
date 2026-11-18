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
 * original to be decoded and reduced inside their request, and keeps the
 * image's size in pixels so the chat reserves its box before it loads (also
 * for a GIF, a sticker or a small picture, which get no preview). Only a
 * speed-up: the attachment routes still make the preview on demand when it
 * is missing (MediaThumbnails::ensure is idempotent), and any failure is
 * ignored.
 */
class WarmMediaThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Tables whose rows keep the measured size of their image.
     *
     * @var list<string>
     */
    public const DIMENSION_TABLES = ['message_attachments', 'team_attachments'];

    /** Images whose size can be read from their header. */
    private const MEASURABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  string|null  $dimensionsTable  Table whose row keeps the measured size (one of DIMENSION_TABLES).
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
     * images a preview can be made of or, when a row keeps the size, for any
     * image that can be measured.
     */
    public static function warm(string $disk, ?string $path, string $mime, ?int $sizeBytes, ?string $dimensionsTable = null, ?int $attachmentId = null): void
    {
        if ($path === null || $path === '' || ! self::worthIt($mime, $sizeBytes, $dimensionsTable !== null && $attachmentId !== null)) {
            return;
        }

        self::dispatch($disk, $path, $mime, $sizeBytes, $dimensionsTable, $attachmentId)->afterCommit();
    }

    /**
     * Whether the job has anything to do for this file: a preview to make,
     * or (when its row keeps the size) an image to measure.
     */
    public static function worthIt(string $mime, ?int $sizeBytes, bool $measures): bool
    {
        return app(MediaThumbnails::class)->supports($mime, $sizeBytes)
            || ($measures && in_array(strtolower($mime), self::MEASURABLE, true));
    }

    public function handle(MediaThumbnails $thumbnails): void
    {
        $mime = strtolower($this->mime);
        $thumbnail = $thumbnails->ensure($this->disk, $this->path, $mime, $this->sizeBytes);

        if ($this->dimensionsTable === null || $this->attachmentId === null || ! in_array($this->dimensionsTable, self::DIMENSION_TABLES, true)) {
            return;
        }

        // The layout hint the chat uses to reserve the image's box.
        $size = $thumbnail !== null && $thumbnail['width'] !== null && $thumbnail['height'] !== null
            ? ['width' => $thumbnail['width'], 'height' => $thumbnail['height']]
            : $thumbnails->measure($this->disk, $this->path, $mime);

        if ($size !== null) {
            DB::table($this->dimensionsTable)
                ->where('id', $this->attachmentId)
                ->whereNull('width')
                ->update($size);
        }
    }
}
