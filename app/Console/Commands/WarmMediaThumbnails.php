<?php

namespace App\Console\Commands;

use App\Enums\AttachmentStatus;
use App\Jobs\CacheMediaLocally;
use App\Jobs\WarmMediaThumbnail;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Services\Storage\LocalMedia;
use App\Services\Storage\MediaPaths;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\MountHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Queues the chat previews of images stored before previews were made in the
 * background (WarmMediaThumbnail), and the measuring of images whose size in
 * pixels is not known yet, so old conversations and internal threads open as
 * fast as new ones and without jumps. Safe to run again: images that already
 * have a preview and a size are skipped.
 */
class WarmMediaThumbnails extends Command
{
    protected $signature = 'media:warm-thumbnails {--limit=0 : Como máximo esta cantidad de imágenes (0 = todas)}';

    protected $description = 'Genera en segundo plano las miniaturas y medidas de las imágenes que aún no tienen';

    /** @var list<string> */
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function handle(MediaThumbnails $thumbnails): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $queued = 0;

        $queue = function (string $disk, ?string $path, string $mime, ?int $size, string $table, int $id, bool $measured) use ($thumbnails, &$queued): void {
            if ($path === null || $path === '') {
                return;
            }

            // Previews of the NAS live on this server: checked here, never on the share.
            $local = app(LocalMedia::class);
            $mounted = MountHealth::settings($disk) !== null;
            $needsPreview = $thumbnails->supports($mime, $size) && ($mounted
                ? ! is_file($local->absolute($disk, MediaPaths::thumbnailFor($path)))
                : ! Storage::disk($disk)->exists(MediaPaths::thumbnailFor($path)));

            // Only on the NAS: brought here first (media-fetch), which makes the preview.
            if ($needsPreview && $mounted && ! $local->hasLocal($disk, $path)) {
                CacheMediaLocally::dispatch($disk, $path, $mime);
                $queued++;

                return;
            }

            if (! $needsPreview && ($measured || ! WarmMediaThumbnail::worthIt($mime, $size, true))) {
                return;
            }

            WarmMediaThumbnail::dispatch($disk, $path, $mime, $size, $table, $id);
            $queued++;
        };

        MessageAttachment::query()
            ->where('status', AttachmentStatus::Available)
            ->whereIn('mime_type', self::IMAGES)
            ->whereNotNull('path')
            ->orderByDesc('id')
            ->lazyById(500)
            ->each(function (MessageAttachment $attachment) use ($queue, $limit, &$queued): bool {
                $queue((string) $attachment->disk, $attachment->path, (string) $attachment->mime_type, $attachment->size_bytes, 'message_attachments', $attachment->id, $attachment->width !== null);

                return $limit === 0 || $queued < $limit;
            });

        if ($limit === 0 || $queued < $limit) {
            TeamAttachment::query()
                ->whereNull('purged_at')
                ->whereIn('mime_type', self::IMAGES)
                ->whereNotNull('path')
                ->orderByDesc('id')
                ->lazyById(500)
                ->each(function (TeamAttachment $attachment) use ($queue, $limit, &$queued): bool {
                    $queue((string) $attachment->disk, $attachment->path, (string) $attachment->mime_type, $attachment->size_bytes, 'team_attachments', $attachment->id, $attachment->width !== null);

                    return $limit === 0 || $queued < $limit;
                });
        }

        $this->info("Imágenes en cola: {$queued}.");

        return self::SUCCESS;
    }
}
