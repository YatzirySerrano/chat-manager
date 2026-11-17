<?php

namespace App\Services\VideoExports;

use App\Exceptions\StorageUnavailable;
use App\Services\PlatformSettings;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The folder browser of «Guardar video» over the NAS: the folders under the
 * root of saved videos (the platform setting «Carpeta de videos de
 * solicitudes», video_exports.root_folder, "VIDEOS" by default) on the disk
 * of saved videos (VIDEO_EXPORTS_DISK, or the media disk).
 *
 * The usual layout is VIDEOS/SUCURSAL/MM-Mes AAAA/Solicitud|Desembolso/DD-MM-AAAA,
 * made by the team itself with «Crear carpeta»; the platform does not
 * force it.
 *
 * A folder is always given relative to the root, as "/"-separated names,
 * and can never leave it: ".", "..", absolute paths, drive letters, empty
 * or control characters and the signs SMB refuses are rejected; on a local
 * (or mounted) disk the resolved real path must also stay inside the real
 * root, so a symbolic link cannot lead out of it, and links are never
 * listed. Every operation first checks that the disk is ready (a mounted
 * NAS is verified with MountHealth) and answers StorageUnavailable when it
 * is not.
 */
class VideoFolders
{
    public const DEFAULT_ROOT = 'VIDEOS';

    /** Deepest folder accepted below the root. */
    private const MAX_DEPTH = 12;

    public function __construct(private PlatformSettings $settings, private MediaDisk $mediaDisk) {}

    /**
     * The disk the copies are written to: VIDEO_EXPORTS_DISK, or the media disk.
     */
    public function disk(): string
    {
        $disk = config('attachments.storage.video_exports_disk');

        return is_string($disk) && $disk !== '' ? $disk : $this->mediaDisk->name();
    }

    /**
     * The configured root folder, as one clean folder name.
     */
    public function root(): string
    {
        return self::normalizeRoot((string) $this->settings->get('video_exports.root_folder', self::DEFAULT_ROOT));
    }

    /**
     * A root folder as typed by an administrator, reduced to one safe folder
     * name (separators become spaces); the default when nothing safe is left.
     */
    public static function normalizeRoot(?string $value): string
    {
        $root = MediaPaths::clean($value, 60);

        return $root !== '' ? $root : self::DEFAULT_ROOT;
    }

    /**
     * The names of a folder relative to the root ("" is the root itself).
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function segments(?string $relative, string $field = 'folder'): array
    {
        $relative = str_replace('\\', '/', trim((string) $relative));

        if ($relative === '') {
            return [];
        }

        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            self::invalid($field);
        }

        $segments = array_values(array_filter(explode('/', $relative), fn (string $segment): bool => $segment !== ''));

        if (count($segments) > self::MAX_DEPTH) {
            self::invalid($field);
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || trim($segment) === '' || mb_strlen($segment) > 120
                || preg_match('/[\x00-\x1F\x7F<>:"|?*]/u', $segment) === 1 || str_starts_with($segment, '.')) {
                self::invalid($field);
            }
        }

        return $segments;
    }

    /**
     * Path on the disk of a folder relative to the root.
     *
     * @param  list<string>  $segments
     */
    public function pathOnDisk(array $segments): string
    {
        return implode('/', [$this->root(), ...$segments]);
    }

    /**
     * The sub folders of a folder, by name (natural order, hidden names left out).
     *
     * @param  list<string>  $segments
     * @return list<string>
     *
     * @throws StorageUnavailable|ValidationException
     */
    public function list(array $segments): array
    {
        $disk = $this->ready();

        if ($segments === [] && ! $this->exists([])) {
            return [];
        }

        $this->assertExists($segments);

        try {
            $names = $this->isLocal($disk)
                ? $this->localDirectories($this->localPath($segments))
                : array_map('basename', Storage::disk($disk)->directories($this->pathOnDisk($segments)));
        } catch (Throwable $exception) {
            throw $this->unavailable($disk, $exception);
        }

        $names = array_values(array_filter($names, fn (string $name): bool => $name !== '' && ! str_starts_with($name, '.')));
        natcasesort($names);

        return array_values($names);
    }

    /**
     * Creates a sub folder with a clean, SMB-safe name and returns that name.
     *
     * @param  list<string>  $segments  The parent folder.
     *
     * @throws StorageUnavailable|ValidationException
     */
    public function create(array $segments, string $name): string
    {
        $disk = $this->ready();
        $clean = MediaPaths::clean($name, 80);

        if ($clean === '' || str_starts_with($clean, '.')) {
            throw ValidationException::withMessages(['name' => 'Escribe un nombre de carpeta con letras o números.']);
        }

        if ($segments !== [] || $this->exists([])) {
            $this->assertExists($segments);
        }

        $target = [...$segments, $clean];

        try {
            if ($this->exists($target)) {
                throw ValidationException::withMessages(['name' => 'Ya existe una carpeta con ese nombre aquí.']);
            }

            if (! Storage::disk($disk)->makeDirectory($this->pathOnDisk($target))) {
                throw new StorageUnavailable('write_failed', $disk);
            }
        } catch (ValidationException|StorageUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($disk, $exception);
        }

        $this->assertExists($target);

        return $clean;
    }

    /**
     * Whether the folder exists (inside the root).
     *
     * @param  list<string>  $segments
     */
    public function exists(array $segments): bool
    {
        $disk = $this->disk();

        try {
            if ($this->isLocal($disk)) {
                $path = $this->localPath($segments);

                return is_dir($path) && ! is_link($path) && $this->inside($path);
            }

            return Storage::disk($disk)->directoryExists($this->pathOnDisk($segments));
        } catch (Throwable $exception) {
            throw $this->unavailable($disk, $exception);
        }
    }

    /**
     * @param  list<string>  $segments
     *
     * @throws ValidationException
     */
    public function assertExists(array $segments): void
    {
        if (! $this->exists($segments)) {
            throw ValidationException::withMessages(['folder' => 'La carpeta ya no existe en Synology. Elige otra.']);
        }
    }

    /**
     * The disk, once ready for files.
     *
     * @throws StorageUnavailable
     */
    public function ready(): string
    {
        $disk = $this->disk();
        $this->mediaDisk->assertReady($disk);

        return $disk;
    }

    /**
     * Spanish explanation of why the NAS cannot be used right now.
     */
    public static function unavailableMessage(StorageUnavailable $exception): string
    {
        return 'Synology no está disponible: '.MediaDisk::explain($exception->reason).'. Inténtalo de nuevo en unos minutos.';
    }

    private function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /**
     * @param  list<string>  $segments
     */
    private function localPath(array $segments): string
    {
        return Storage::disk($this->disk())->path($this->pathOnDisk($segments));
    }

    /**
     * Whether a local path resolves inside the real root (no link leads out).
     */
    private function inside(string $path): bool
    {
        $root = realpath($this->localPath([]));
        $real = realpath($path);

        if ($root === false || $real === false) {
            return false;
        }

        return $real === $root || str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * Real sub directories of a local directory; symbolic links are skipped.
     *
     * @return list<string>
     */
    private function localDirectories(string $path): array
    {
        $entries = @scandir($path);

        if ($entries === false) {
            throw new StorageUnavailable('read_failed', $this->disk());
        }

        return array_values(array_filter($entries, fn (string $entry): bool => $entry !== '.' && $entry !== '..'
            && ! is_link($path.'/'.$entry) && is_dir($path.'/'.$entry)));
    }

    private function unavailable(string $disk, Throwable $exception): StorageUnavailable
    {
        if ($exception instanceof StorageUnavailable) {
            return $exception;
        }

        Log::warning('The folders of saved videos could not be read.', ['disk' => $disk, 'exception' => $exception::class]);

        return new StorageUnavailable('read_failed', $disk);
    }

    /**
     * @throws ValidationException
     */
    private static function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'La carpeta no es válida: elige una carpeta dentro de la carpeta de videos.']);
    }
}
