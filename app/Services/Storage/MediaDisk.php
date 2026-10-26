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

        return $driver !== null && $driver !== 'local';
    }

    public function isRequired(): bool
    {
        return (bool) config('attachments.storage.media_disk_required', false);
    }

    /**
     * Why new files cannot be stored right now (a short reason code, see
     * StorageUnavailable), or null when the disk is ready. Configuration
     * only: no network call.
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
            default => 'el almacenamiento de archivos no respondió',
        };
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
