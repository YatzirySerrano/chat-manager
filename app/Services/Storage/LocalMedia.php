<?php

namespace App\Services\Storage;

use App\Exceptions\StorageUnavailable;
use App\Jobs\CacheMediaLocally;
use App\Jobs\SyncMediaReplica;
use App\Models\Storage\MediaReplica;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local first storage of a mounted media disk (the NAS share).
 *
 * The NAS is the permanent destination, never a single point of failure:
 * every new file of a mounted disk is written to this server first
 * ("media_local" disk, under "{disk}/{path}"), verified (size and SHA-256)
 * and recorded in media_replicas as "pending"; it can be served, sent and
 * downloaded at once. The media-sync workers (SyncMediaReplica) copy it to
 * the NAS afterwards, verify the remote copy and mark it "synced"; the local
 * copy then stays as a cache until media:prune-local removes it.
 *
 * The attachment rows keep the NAS disk name and the same path, so ids,
 * URLs and historical files are untouched: readers resolve each file
 * through open(), which prefers the local copy and only touches the NAS
 * when its last health check passed (never waiting for a dead share).
 */
class LocalMedia
{
    private const PENDING_BYTES_KEY = 'storage:local-media:pending-bytes';

    private const ACCESS_PREFIX = 'storage:local-media:access:';

    private const FETCH_PREFIX = 'storage:local-media:fetch:';

    public function __construct(private MountHealth $mounts) {}

    /**
     * Whether new files of the disk are written here first.
     */
    public function enabledFor(string $disk): bool
    {
        return (bool) config('attachments.storage.local_first.enabled', true)
            && $disk !== $this->diskName()
            && MountHealth::settings($disk) !== null;
    }

    public function diskName(): string
    {
        return (string) config('attachments.storage.local_first.disk', 'media_local');
    }

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Path of a file of the given disk inside the local disk.
     */
    public function localPath(string $disk, string $path): string
    {
        return $disk.'/'.ltrim($path, '/');
    }

    public function absolute(string $disk, string $path): string
    {
        return rtrim($this->root(), '/').'/'.$this->localPath($disk, $path);
    }

    public function root(): string
    {
        return (string) config('filesystems.disks.'.$this->diskName().'.root', storage_path('app/private/media-local'));
    }

    /**
     * Whether a file goes to the NAS (MEDIA_SYNC_TYPES): with "video" only
     * videos do (they are what fills the server); images, PDF and the rest
     * stay here, served at once and never touching the NAS.
     */
    public function syncable(string $path, ?string $absolute = null): bool
    {
        if (strtolower((string) config('attachments.storage.local_first.sync_types', 'video')) === 'all') {
            return true;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['mp4', 'mov', 'm4v', '3gp', '3gpp', 'webm', 'mkv', 'avi', 'mpeg', 'mpg', 'ogv'], true)) {
            return true;
        }

        if ($extension !== '' || $absolute === null || ! is_file($absolute)) {
            return false;
        }

        $mime = @mime_content_type($absolute);

        return is_string($mime) && str_starts_with($mime, 'video/');
    }

    public function hasLocal(string $disk, string $path): bool
    {
        // A copy this process cannot read (wrong owner) counts as absent: the
        // NAS copy is used instead of failing.
        $absolute = $this->absolute($disk, $path);

        return $path !== '' && is_file($absolute) && is_readable($absolute);
    }

    /**
     * Why a new file cannot be kept here right now ("local_full": the
     * reserved free space would be used, "local_pending_full": too many
     * bytes still waiting for the NAS), or null. Local checks only.
     */
    public function localProblem(int $incomingBytes = 0): ?string
    {
        $root = $this->root();

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            return 'local_unwritable';
        }

        $free = @disk_free_space($root);

        if ($free !== false && $free - $incomingBytes < $this->minFreeBytes()) {
            return 'local_full';
        }

        if ($this->pendingBytes() + $incomingBytes > max(0, (int) config('attachments.storage.local_first.pending_max_bytes', 80 * 1024 ** 3))) {
            return 'local_pending_full';
        }

        return null;
    }

    public function minFreeBytes(): int
    {
        return max(0, (int) config('attachments.storage.local_first.min_free_bytes', 40 * 1024 ** 3));
    }

    /**
     * Bytes that exist only here (not yet verified on the NAS), cached for
     * a few seconds: it is read before every write.
     */
    public function pendingBytes(bool $fresh = false): int
    {
        $compute = fn (): int => (int) MediaReplica::query()->whereIn('state', [MediaReplica::PENDING, MediaReplica::CONFLICT])->sum('size_bytes');

        if ($fresh) {
            return $compute();
        }

        try {
            return (int) Cache::remember(self::PENDING_BYTES_KEY, 20, $compute);
        } catch (Throwable) {
            return $compute();
        }
    }

    /**
     * Writes a local file as "{disk}/{path}" on the local disk: temporary
     * file, hash while copying, fsync, rename into place (never replacing a
     * file), size and hash checked again. Then the pending replica row and
     * its synchronization job (after the surrounding transaction commits).
     *
     * @throws StorageUnavailable
     */
    public function store(string $source, string $disk, string $path, int $size): void
    {
        $final = $this->absolute($disk, $path);
        $directory = dirname($final);
        $temporary = $directory.'/.'.basename($final).'.'.Str::random(12).'.part';
        $renamed = false;

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new StorageUnavailable('local_unwritable', $this->diskName());
            }

            $hash = $this->copy($source, $temporary, $size);

            if (file_exists($final) || ! @rename($temporary, $final)) {
                throw new StorageUnavailable('write_failed', $this->diskName());
            }

            $renamed = true;
            clearstatcache(true, $final);

            if (@filesize($final) !== $size || @hash_file('sha256', $final) !== $hash) {
                throw new StorageUnavailable('verify_failed', $this->diskName());
            }

            $replica = MediaReplica::query()->updateOrCreate(
                ['path_hash' => MediaReplica::hashFor($disk, $path)],
                [
                    'disk' => $disk,
                    'path' => ltrim($path, '/'),
                    'origin' => MediaReplica::ORIGIN_UPLOAD,
                    'state' => $this->syncable($path, $final) ? MediaReplica::PENDING : MediaReplica::KEPT,
                    'size_bytes' => $size,
                    'sha256' => $hash,
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                    'claimed_until' => null,
                    'last_error' => null,
                    'synced_at' => null,
                    'local_present' => true,
                    'local_purged_at' => null,
                    'last_accessed_at' => now(),
                ],
            );
        } catch (StorageUnavailable $exception) {
            if ($renamed) {
                @unlink($final);
            }

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Local media write failed.', ['disk' => $disk, 'exception' => $exception::class]);

            if ($renamed) {
                @unlink($final);
            }

            throw new StorageUnavailable('write_failed', $this->diskName());
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->forgetPendingBytes();
        Cache::put('storage:local-media:last-write', now()->getTimestamp(), 86400 * 30);

        if ($replica->state === MediaReplica::PENDING) {
            SyncMediaReplica::dispatch($replica->id)->afterCommit();
        }
    }

    /**
     * Keeps a copy of a file that is only on the NAS (CacheMediaLocally):
     * same atomic write, recorded as an already synchronized cache entry.
     */
    public function adopt(string $source, string $disk, string $path): bool
    {
        $size = is_file($source) ? (int) filesize($source) : -1;

        if ($size < 0 || $this->hasLocal($disk, $path) || $this->localProblem($size) === 'local_full') {
            return false;
        }

        $final = $this->absolute($disk, $path);
        $directory = dirname($final);
        $temporary = $directory.'/.'.basename($final).'.'.Str::random(12).'.part';

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return false;
            }

            $hash = $this->copy($source, $temporary, $size);

            if (file_exists($final) || ! @rename($temporary, $final)) {
                return false;
            }

            MediaReplica::query()->updateOrCreate(
                ['path_hash' => MediaReplica::hashFor($disk, $path)],
                [
                    'disk' => $disk,
                    'path' => ltrim($path, '/'),
                    'origin' => MediaReplica::ORIGIN_CACHE,
                    'state' => MediaReplica::SYNCED,
                    'size_bytes' => $size,
                    'sha256' => $hash,
                    'synced_at' => now(),
                    'local_present' => true,
                    'local_purged_at' => null,
                    'last_accessed_at' => now(),
                ],
            );

            return true;
        } catch (Throwable $exception) {
            Log::warning('Local media cache write failed.', ['disk' => $disk, 'exception' => $exception::class]);

            return false;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Where to read a file from: the local copy when there is one, else the
     * disk itself. A mounted disk is only touched while its last health
     * check passed (cached, never probed here): otherwise the file is
     * "unavailable for now" (StorageUnavailable, answered 503) instead of a
     * request hanging on a dead share. Null when the file does not exist.
     *
     * @return array{filesystem: Filesystem, path: string, absolute: string|null, local: bool, remote: bool}|null
     *
     * @throws StorageUnavailable
     */
    public function open(string $disk, string $path, bool $cacheOnRead = true, ?int $sizeBytes = null, ?string $mime = null): ?array
    {
        if ($path === '') {
            return null;
        }

        if (MountHealth::settings($disk) !== null) {
            if ($this->hasLocal($disk, $path)) {
                $this->touch($disk, $path);

                return [
                    'filesystem' => $this->filesystem(),
                    'path' => $this->localPath($disk, $path),
                    'absolute' => $this->absolute($disk, $path),
                    'local' => true,
                    'remote' => false,
                ];
            }

            if (! $this->remoteReadable($disk)) {
                throw new StorageUnavailable('remote_unavailable', $disk, 'El archivo está guardado en el NAS y el NAS no responde en este momento. Se mostrará en cuanto vuelva.');
            }

            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                return null;
            }

            if ($cacheOnRead) {
                $this->fetchLater($disk, $path, $sizeBytes, $mime);
            }

            return [
                'filesystem' => $filesystem,
                'path' => $path,
                'absolute' => null,
                'local' => false,
                'remote' => true,
            ];
        }

        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            return null;
        }

        $local = config("filesystems.disks.{$disk}.driver") === 'local';

        return [
            'filesystem' => $filesystem,
            'path' => $path,
            'absolute' => $local ? $filesystem->path($path) : null,
            'local' => $local,
            'remote' => false,
        ];
    }

    /**
     * Whether a request may read the NAS directly: allowed by configuration
     * and the last cached health check passed.
     */
    public function remoteReadable(string $disk): bool
    {
        // Viewing and downloading are never held back by the NAS being slow
        // or by the background copies: only a share that is not mounted or
        // failed its last probes in a row is not touched.
        return (bool) config('attachments.storage.local_first.remote_reads', true)
            && ! in_array($this->mounts->state($disk), ['unmounted', 'disconnected'], true);
    }

    /**
     * Brings a file that is only on the NAS to the local cache in the
     * background (media-fetch queue), at most once every ten minutes.
     */
    public function fetchLater(string $disk, string $path, ?int $sizeBytes = null, ?string $mime = null): void
    {
        $limit = (int) config('attachments.storage.local_first.cache_on_read_max_bytes', 64 * 1024 ** 2);

        if ($sizeBytes !== null && $sizeBytes > $limit) {
            return;
        }

        try {
            if (Cache::add(self::FETCH_PREFIX.sha1($disk.'|'.$path), 1, 600)) {
                CacheMediaLocally::dispatch($disk, $path, $mime);
            }
        } catch (Throwable) {
            // Only a warm-up: the file is still served from the NAS.
        }
    }

    /**
     * Removes the local copy, its preview and its replica row (a discarded
     * upload). The NAS copy, if any, is the caller's business.
     */
    public function forget(string $disk, string $path): void
    {
        if ($path === '') {
            return;
        }

        @unlink($this->absolute($disk, $path));

        foreach (MediaThumbnails::pathsFor($path) as $thumbnail) {
            @unlink($this->absolute($disk, $thumbnail));
        }

        try {
            MediaReplica::query()->where('path_hash', MediaReplica::hashFor($disk, $path))->delete();
        } catch (Throwable $exception) {
            Log::warning('Local media replica row could not be removed.', ['disk' => $disk, 'exception' => $exception::class]);
        }

        $this->forgetPendingBytes();
    }

    /**
     * Whether the path is taken here (local copy or a replica row): a new
     * name never needs the NAS to be asked.
     */
    public function taken(string $disk, string $path): bool
    {
        return $this->hasLocal($disk, $path)
            || MediaReplica::query()->where('path_hash', MediaReplica::hashFor($disk, $path))->exists();
    }

    /**
     * Whether a file still waits for its NAS copy (it must not be moved or
     * deleted on the NAS side).
     */
    public function pending(string $disk, string $path): bool
    {
        return MediaReplica::query()
            ->where('path_hash', MediaReplica::hashFor($disk, $path))
            ->where('state', '!=', MediaReplica::SYNCED)
            ->exists();
    }

    public function forgetPendingBytes(): void
    {
        try {
            Cache::forget(self::PENDING_BYTES_KEY);
        } catch (Throwable) {
            // It expires by itself.
        }
    }

    /**
     * Last access of a cached copy (the prune keeps the ones in use), written
     * at most once an hour per file.
     */
    private function touch(string $disk, string $path): void
    {
        try {
            $hash = MediaReplica::hashFor($disk, $path);

            if (Cache::add(self::ACCESS_PREFIX.$hash, 1, 3600)) {
                MediaReplica::query()->where('path_hash', $hash)->toBase()->update(['last_accessed_at' => now()]);
            }
        } catch (Throwable) {
            // Only a hint for the prune.
        }
    }

    /**
     * Streams the source into the target, fsyncs it and returns its SHA-256.
     *
     * @throws StorageUnavailable
     */
    private function copy(string $source, string $target, int $size): string
    {
        $input = @fopen($source, 'rb');

        if ($input === false) {
            throw new StorageUnavailable('read_failed', $this->diskName(), 'No fue posible leer el archivo para guardarlo. Inténtalo de nuevo.');
        }

        $output = @fopen($target, 'xb');

        if ($output === false) {
            fclose($input);

            throw new StorageUnavailable('local_unwritable', $this->diskName());
        }

        $context = hash_init('sha256');
        $written = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw new StorageUnavailable('read_failed', $this->diskName(), 'No fue posible leer el archivo para guardarlo. Inténtalo de nuevo.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($context, $chunk);

                if (@fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new StorageUnavailable('local_full', $this->diskName());
                }

                $written += strlen($chunk);
            }

            if (! @fflush($output) || (function_exists('fsync') && ! @fsync($output))) {
                throw new StorageUnavailable('write_failed', $this->diskName());
            }
        } finally {
            fclose($input);
            @fclose($output);
        }

        if ($written !== $size) {
            throw new StorageUnavailable('write_failed', $this->diskName());
        }

        return hash_final($context);
    }
}
