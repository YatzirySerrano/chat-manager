<?php

namespace App\Services\Storage;

use App\Enums\AttachmentStatus;
use App\Exceptions\StorageUnavailable;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The private disk of conversation media (`filesystems.media_disk`,
 * MEDIA_DISK): customer files, agent attachments and the web chat avatar.
 * The database only keeps metadata (disk, path, type, size, hash); every
 * row remembers its own disk, so switching MEDIA_DISK only affects new
 * files and existing ones keep resolving from the disk they were saved on
 * (the `local` disk must therefore never be removed).
 *
 * Explicit behaviour instead of silent fallbacks:
 * - an external disk (any driver other than local, e.g. an S3-compatible
 *   bucket) that is not fully configured is "not ready": files are refused
 *   with StorageUnavailable, never written to the server's disk instead;
 * - with `attachments.storage.media_disk_required` the local disk is not
 *   accepted either, so the VPS is never the primary store;
 * - every write is verified (the write result, then the file's existence
 *   and size) before the caller records the file as available.
 *
 * Readiness only reads configuration (cheap enough for every request);
 * the write-and-delete probe (probe()) runs only in app:preflight.
 *
 * Mounted disks (opt-in): a "local" disk with a "mount" section (the "nas"
 * disk, a NAS share mounted on this server, MEDIA_DISK=nas) counts as
 * external storage, also for MEDIA_DISK_REQUIRED, but only while the mount
 * is verified (MountHealth): the last bounded health probe passed, the
 * kernel mount table shows the expected filesystem at the root and the
 * identity marker holds MEDIA_MOUNT_ID. Otherwise files are refused, so an
 * unmounted share (an empty directory of the server) never receives them.
 * Writes to a mount go to a temporary file in the same directory, are
 * renamed into place only when complete and are checked again (size, and
 * SHA-256 re-read when attachments.storage.mount.verify_hash). Disks
 * without a "mount" section behave exactly as before.
 */
class MediaDisk
{
    private const S3_ADAPTER = 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter';

    public function name(): string
    {
        $name = (string) config('filesystems.media_disk', 'local');

        return $name !== '' ? $name : 'local';
    }

    public function driver(?string $disk = null): ?string
    {
        $driver = config('filesystems.disks.'.($disk ?? $this->name()).'.driver');

        return is_string($driver) ? $driver : null;
    }

    /**
     * Whether files of the disk live outside this server's filesystem.
     */
    public function isExternal(?string $disk = null): bool
    {
        $driver = $this->driver($disk);

        return $driver !== null && ($driver !== 'local' || $this->isMount($disk));
    }

    /**
     * Whether the disk is a network share mounted on this server (a local
     * disk with a "mount" section, see MountHealth).
     */
    public function isMount(?string $disk = null): bool
    {
        return MountHealth::settings($disk ?? $this->name()) !== null;
    }

    public function isRequired(): bool
    {
        return (bool) config('attachments.storage.media_disk_required', false);
    }

    /**
     * Why new files cannot be stored right now (a short reason code, see
     * StorageUnavailable), or null when the disk is ready. Configuration
     * only: no network call. A mounted disk also reads its cached health
     * and identity marker (see mountProblem()).
     */
    public function problem(): ?string
    {
        $config = config('filesystems.disks.'.$this->name());

        if (! is_array($config) || ! is_string($config['driver'] ?? null)) {
            return 'not_configured';
        }

        if ($this->name() === 'public' || ($config['visibility'] ?? null) === 'public') {
            return 'public_disk';
        }

        if ($config['driver'] === 'local' && $this->isMount()) {
            return $this->mountProblem();
        }

        if ($config['driver'] === 'local') {
            return $this->isRequired() ? 'local_not_allowed' : null;
        }

        if ($config['driver'] === 's3') {
            if (! class_exists(self::S3_ADAPTER)) {
                return 'adapter_missing';
            }

            if (blank($config['bucket'] ?? null) || blank($config['region'] ?? null)) {
                return 'not_configured';
            }

            // Either both keys or none (instance role); never just one.
            if (blank($config['key'] ?? null) !== blank($config['secret'] ?? null)) {
                return 'not_configured';
            }
        }

        return null;
    }

    public function ready(): bool
    {
        return $this->problem() === null;
    }

    /**
     * @throws StorageUnavailable
     */
    public function assertReady(): void
    {
        $problem = $this->problem();

        if ($problem !== null) {
            throw new StorageUnavailable($problem, $this->name());
        }
    }

    /**
     * Copies a local file to the media disk under {directory}/{uuid} (or
     * the given file name) and verifies it arrived complete.
     *
     * @return array{disk: string, path: string}
     *
     * @throws StorageUnavailable
     */
    public function putFile(string $localPath, string $directory, ?string $fileName = null): array
    {
        $this->assertReady();

        $disk = $this->name();
        $path = trim($directory, '/').'/'.($fileName ?? (string) Str::uuid());
        $size = is_file($localPath) ? (int) filesize($localPath) : -1;
        $stream = $size >= 0 ? @fopen($localPath, 'rb') : false;

        if ($stream === false) {
            throw new StorageUnavailable('read_failed', $disk, 'No fue posible leer el archivo para guardarlo. Inténtalo de nuevo.');
        }

        if ($this->isMount($disk)) {
            try {
                $this->putOnMount($disk, $stream, $path, $size);
            } finally {
                fclose($stream);
            }

            return ['disk' => $disk, 'path' => $path];
        }

        $filesystem = Storage::disk($disk);

        try {
            $written = $filesystem->writeStream($path, $stream, ['visibility' => 'private']);
        } catch (Throwable $exception) {
            $this->logFailure('write_failed', $disk, $exception);
            $written = false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            $this->discard($disk, $path);

            throw new StorageUnavailable('write_failed', $disk);
        }

        if (! $this->verify($filesystem, $path, $size)) {
            $this->discard($disk, $path);

            throw new StorageUnavailable('verify_failed', $disk);
        }

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * Best-effort removal of a file nothing references (a rolled back or
     * duplicated upload). Never throws.
     */
    public function discard(string $disk, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            $this->logFailure('delete_failed', $disk, $exception);
        }
    }

    /**
     * Writes and deletes a tiny probe file (app:preflight only). Null when
     * it worked, else the short reason.
     */
    public function probe(): ?string
    {
        $problem = $this->problem();

        if ($problem !== null) {
            return $problem;
        }

        if ($this->isMount()) {
            // A fresh bounded probe in its own process (a dead share cannot hang this one).
            return app(MountHealth::class)->refresh($this->name());
        }

        $disk = Storage::disk($this->name());
        $path = 'health/preflight-'.Str::uuid().'.txt';

        try {
            if ($disk->put($path, 'ok', ['visibility' => 'private']) === false || $disk->get($path) !== 'ok') {
                return 'write_failed';
            }

            $disk->delete($path);

            return $disk->exists($path) ? 'delete_failed' : null;
        } catch (Throwable $exception) {
            $this->logFailure('probe_failed', $this->name(), $exception);

            return 'probe_failed';
        }
    }

    public function quotaBytes(): int
    {
        return max(0, (int) config('attachments.storage.quota_bytes', 0));
    }

    public function warningPercent(): int
    {
        return min(100, max(1, (int) config('attachments.storage.warning_percent', 80)));
    }

    /**
     * Bytes recorded on the media disk: available conversation attachments
     * plus team chat files kept on the same disk. Read from the metadata
     * (SUM of size_bytes), never by listing the bucket.
     */
    public function usageBytes(): int
    {
        $disk = $this->name();

        $messages = (int) MessageAttachment::query()
            ->where('disk', $disk)
            ->where('status', AttachmentStatus::Available)
            ->sum('size_bytes');

        $team = (int) TeamAttachment::query()
            ->where('disk', $disk)
            ->whereNull('purged_at')
            ->sum('size_bytes');

        return $messages + $team;
    }

    /**
     * Usage against the quota: level "ok", "warning" (at or above the
     * warning percent) or "exceeded"; "unlimited" without a quota.
     *
     * @return array{disk: string, used_bytes: int, quota_bytes: int, percent: int|null, level: string}
     */
    public function usage(?int $usedBytes = null): array
    {
        $used = $usedBytes ?? $this->usageBytes();
        $quota = $this->quotaBytes();
        $percent = $quota > 0 ? (int) floor($used * 100 / $quota) : null;

        $level = match (true) {
            $percent === null => 'unlimited',
            $percent >= 100 => 'exceeded',
            $percent >= $this->warningPercent() => 'warning',
            default => 'ok',
        };

        return ['disk' => $this->name(), 'used_bytes' => $used, 'quota_bytes' => $quota, 'percent' => $percent, 'level' => $level];
    }

    /**
     * Spanish explanation of a readiness problem for administrators (no
     * credentials or endpoints, only the variable names to review).
     */
    public static function explain(string $problem): string
    {
        return match ($problem) {
            'adapter_missing' => 'falta el componente de almacenamiento compatible con S3 en el servidor (league/flysystem-aws-s3-v3)',
            'not_configured' => 'el disco de archivos no está completo (revisa MEDIA_DISK y AWS_BUCKET, AWS_DEFAULT_REGION, AWS_ACCESS_KEY_ID y AWS_SECRET_ACCESS_KEY)',
            'local_not_allowed' => 'MEDIA_DISK_REQUIRED exige un almacenamiento externo, pero MEDIA_DISK usa el disco del servidor',
            'public_disk' => 'el disco de archivos es público; los adjuntos deben guardarse en un disco privado',
            'write_failed', 'probe_failed' => 'no se pudo escribir un archivo de prueba en el almacenamiento',
            'delete_failed' => 'no se pudo borrar el archivo de prueba del almacenamiento',
            'not_mounted' => 'la carpeta compartida del NAS no está montada en el servidor (MEDIA_MOUNT_PATH); revisa el montaje CIFS/SMB y Tailscale',
            'wrong_filesystem' => 'en MEDIA_MOUNT_PATH hay montado un sistema de archivos distinto del esperado (MEDIA_MOUNT_FSTYPES)',
            'marker_missing' => 'no se encontró el archivo de identidad del almacenamiento (MEDIA_MOUNT_MARKER) en la carpeta montada: el NAS no está montado o es otra carpeta',
            'marker_mismatch' => 'el archivo de identidad de la carpeta montada no coincide con MEDIA_MOUNT_ID: es otro almacenamiento',
            'probe_timeout' => 'el NAS no respondió a tiempo a la prueba de salud (montaje caído o red lenta)',
            default => 'el almacenamiento de archivos no respondió',
        };
    }

    /**
     * Mounted disk readiness: the cached bounded probe, then the cheap checks
     * (mount table and identity marker), on every readiness check and so
     * before every write.
     */
    private function mountProblem(): ?string
    {
        $health = app(MountHealth::class);
        $disk = $this->name();

        // The cached probe first: when it says the share is down, the share is not touched at all.
        return MountHealth::configurationProblem($disk) ?? $health->cachedProblem($disk) ?? $health->cheapProblem($disk);
    }

    /**
     * Writes the stream to a temporary file next to the final path, hashing
     * it on the way, renames it into place (same filesystem: the final name
     * never shows a partial file) and verifies size, hash and that the mount
     * is still the expected storage. Nothing is left behind on failure.
     *
     * @param  resource  $stream
     *
     * @throws StorageUnavailable
     */
    private function putOnMount(string $disk, $stream, string $path, int $size): void
    {
        $root = (string) MountHealth::settings($disk)['root'];
        $final = $root.'/'.$path;
        $directory = dirname($final);
        $temporary = $directory.'/.'.basename($final).'.'.Str::random(12).'.part';
        $renamed = false;
        $reason = 'write_failed';

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new StorageUnavailable('write_failed', $disk);
            }

            $output = @fopen($temporary, 'xb');

            if ($output === false) {
                throw new StorageUnavailable('write_failed', $disk);
            }

            $context = hash_init('sha256');
            $written = 0;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        throw new StorageUnavailable('read_failed', $disk, 'No fue posible leer el archivo para guardarlo. Inténtalo de nuevo.');
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    hash_update($context, $chunk);

                    if (@fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new StorageUnavailable('write_failed', $disk);
                    }

                    $written += strlen($chunk);
                }

                if (! @fflush($output)) {
                    throw new StorageUnavailable('write_failed', $disk);
                }
            } finally {
                $closed = @fclose($output);
            }

            if (! $closed || $written !== $size) {
                throw new StorageUnavailable('write_failed', $disk);
            }

            $hash = hash_final($context);

            // UUID names never collide; a file already there is never replaced.
            if (file_exists($final) || ! @rename($temporary, $final)) {
                throw new StorageUnavailable('write_failed', $disk);
            }

            $renamed = true;
            $reason = 'verify_failed';
            clearstatcache(true, $final);

            if (@filesize($final) !== $size) {
                throw new StorageUnavailable('verify_failed', $disk);
            }

            if ((bool) config('attachments.storage.mount.verify_hash', true) && @hash_file('sha256', $final) !== $hash) {
                throw new StorageUnavailable('verify_failed', $disk);
            }

            // The share may have dropped while writing: then the file is on the server's disk.
            $problem = app(MountHealth::class)->cheapProblem($disk);

            if ($problem !== null) {
                throw new StorageUnavailable($problem, $disk);
            }
        } catch (StorageUnavailable $exception) {
            if ($renamed) {
                @unlink($final);
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure($reason, $disk, $exception);

            if ($renamed) {
                @unlink($final);
            }

            throw new StorageUnavailable($reason, $disk);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function verify(Filesystem $filesystem, string $path, int $size): bool
    {
        try {
            return $filesystem->exists($path) && $filesystem->size($path) === $size;
        } catch (Throwable $exception) {
            $this->logFailure('verify_failed', $this->name(), $exception);

            return false;
        }
    }

    /**
     * Only the exception class: its message may carry endpoints or bucket names.
     */
    private function logFailure(string $reason, string $disk, Throwable $exception): void
    {
        Log::warning('Media storage operation failed.', ['reason' => $reason, 'disk' => $disk, 'exception' => $exception::class]);
    }
}
