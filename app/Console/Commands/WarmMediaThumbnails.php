<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Jobs\WarmMediaThumbnail;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Services\Storage\MediaPaths;
use App\Services\Storage\MediaThumbnails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Queues the chat previews of images stored before previews were made in the
 * background (WarmMediaThumbnail), so old conversations open as fast as new
 * ones. Safe to run again: images that already have a preview are skipped.
 */
class WarmMediaThumbnails extends Command
{
    protected $signature = 'media:warm-thumbnails {--limit=0 : Como máximo esta cantidad de imágenes (0 = todas)}';

    protected $description = 'Genera en segundo plano las miniaturas de las imágenes que aún no tienen';

    public function handle(MediaThumbnails $thumbnails): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $queued = 0;

        $queue = function (string $disk, ?string $path, string $mime, ?int $size, ?string $table, ?int $id) use ($thumbnails, &$queued): bool {
            if ($path === null || $path === '' || ! $thumbnails->supports($mime, $size)) {
                return true;
            }

            if (Storage::disk($disk)->exists(MediaPaths::thumbnailFor($path))) {
                return true;
            }

            WarmMediaThumbnail::dispatch($disk, $path, $mime, $size, $table, $id);
            $queued++;

            return true;
        };

        MessageAttachment::query()
            ->where('status', AttachmentStatus::Available)
            ->whereIn('mime_type', ['image/jpeg', 'image/png'])
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->lazyById(500)
            ->each(function (MessageAttachment $attachment) use ($queue, $limit, &$queued): bool {
                $queue((string) $attachment->disk, $attachment->path, (string) $attachment->mime_type, $attachment->size_bytes, 'message_attachments', $attachment->id);

                return $limit === 0 || $queued < $limit;
            });

        TeamAttachment::query()
            ->whereNull('purged_at')
            ->whereIn('mime_type', ['image/jpeg', 'image/png'])
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->lazyById(500)
            ->each(function (TeamAttachment $attachment) use ($queue, $limit, &$queued): bool {
                $queue((string) $attachment->disk, $attachment->path, (string) $attachment->mime_type, $attachment->size_bytes, null, null);

                return $limit === 0 || $queued < $limit;
            });

        $this->info("Miniaturas en cola: {$queued}.");

        return self::SUCCESS;
    }
}
