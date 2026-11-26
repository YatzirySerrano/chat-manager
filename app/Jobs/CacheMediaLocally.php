<?php

namespace App\Jobs;

use App\Services\Storage\LocalMedia;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\MountHealth;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings a file that is only on the NAS (a historical file, or one whose
 * local copy was pruned) back to the local cache, on its own queue
 * (media-fetch), so the next view is served from this server, and makes its
 * preview here. Only while the NAS answers; bounded by
 * cache_on_read_max_bytes; never twice at once.
 */
class CacheMediaLocally implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public string $disk, public string $path, public ?string $mime = null)
    {
        $this->onQueue(Queues::name(Queues::MEDIA_FETCH));
    }

    public function uniqueId(): string
    {
        return sha1($this->disk.'|'.$this->path);
    }

    public function handle(LocalMedia $local, MountHealth $mounts, MediaThumbnails $thumbnails): void
    {
        $settings = MountHealth::settings($this->disk);

        if ($settings === null || ! $mounts->remoteAllowed($this->disk)) {
            return;
        }

        if (! $local->hasLocal($this->disk, $this->path)) {
            $this->copy($local, $settings['root'].'/'.ltrim($this->path, '/'));
        }

        if ($this->mime !== null && $local->hasLocal($this->disk, $this->path)) {
            $thumbnails->ensure($this->disk, $this->path, $this->mime);
        }
    }

    private function copy(LocalMedia $local, string $source): void
    {
        $limit = max(1, (int) config('attachments.storage.local_first.cache_on_read_max_bytes', 64 * 1024 ** 2));
        $temporary = tempnam(sys_get_temp_dir(), 'media-cache-');

        if ($temporary === false) {
            return;
        }

        try {
            $input = @fopen($source, 'rb');

            if ($input === false) {
                return;
            }

            $output = @fopen($temporary, 'wb');

            if ($output === false) {
                fclose($input);

                return;
            }

            $copied = stream_copy_to_stream($input, $output, $limit + 1);
            fclose($input);
            fclose($output);

            if ($copied !== false && $copied <= $limit) {
                $local->adopt($temporary, $this->disk, $this->path);
            }
        } catch (Throwable $exception) {
            Log::warning('A NAS file could not be cached locally.', ['disk' => $this->disk, 'exception' => $exception::class]);
        } finally {
            @unlink($temporary);
        }
    }
}
