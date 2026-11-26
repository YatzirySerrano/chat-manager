<?php

namespace App\Jobs;

use App\Services\Storage\LocalMedia;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\MountHealth;
use App\Services\Storage\NasGate;
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

    /** Released back to the queue while the NAS slots are busy. */
    public int $tries = 30;

    public int $maxExceptions = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public string $disk, public string $path, public ?string $mime = null)
    {
        $this->onQueue(Queues::name(Queues::MEDIA_FETCH));
    }

    public function uniqueId(): string
    {
        return sha1($this->disk.'|'.$this->path);
    }

    public function handle(LocalMedia $local, MountHealth $mounts, MediaThumbnails $thumbnails, NasGate $gate): void
    {
        $settings = MountHealth::settings($this->disk);

        if ($settings === null || ! $mounts->remoteAllowed($this->disk)) {
            return;
        }

        if (! $local->hasLocal($this->disk, $this->path)) {
            // Its turn on the NAS: otherwise back to the queue for a while.
            $slot = $gate->acquire($this->disk, 'fetch', $this->timeout + 60);

            if ($slot === null) {
                $this->release(15 + mt_rand(0, 30));

                return;
            }

            try {
                $this->copy($local, $gate, $settings['root'].'/'.ltrim($this->path, '/'));
            } finally {
                $gate->release($slot);
            }
        }

        if ($this->mime !== null && $local->hasLocal($this->disk, $this->path)) {
            $thumbnails->ensure($this->disk, $this->path, $this->mime);
        }
    }

    private function copy(LocalMedia $local, NasGate $gate, string $source): void
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

            $copied = 0;
            $started = microtime(true);

            while (! feof($input) && $copied <= $limit) {
                $chunk = fread($input, 512 * 1024);

                if ($chunk === false || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                    $copied = false;

                    break;
                }

                $copied += strlen($chunk);
                $gate->throttle($copied, $started);
            }

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
