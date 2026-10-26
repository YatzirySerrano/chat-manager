<?php

namespace App\Services\Migration\History;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Defensive reading of uploaded ZIP files (Whaticket media ZIPs, WhatsApp
 * chat exports). Nothing is ever extracted with ZipArchive::extractTo():
 *
 * - every entry is inspected in the central directory before anything is
 *   read: absolute paths, "..", drive letters, backslashes and NUL bytes
 *   (ZIP slip), symbolic links (Unix mode in the external attributes),
 *   encrypted entries, more entries, total uncompressed size, entry size
 *   or compression ratio (zip bomb) than config/history-import.php allows
 *   make the whole file refused;
 * - one entry is copied on demand to a private temporary file through a
 *   stream capped at its declared size (a lying header cannot write more),
 *   and the caller deletes it.
 *
 * @phpstan-type ZipEntry array{index: int, name: string, base: string, size: int}
 */
class SafeZip
{
    private const S_IFMT = 0170000;

    private const S_IFLNK = 0120000;

    private const S_IFDIR = 0040000;

    /**
     * Files of the ZIP (directories left out), after checking all of them.
     *
     * @return list<ZipEntry>
     *
     * @throws ValidationException
     */
    public function entries(string $path): array
    {
        $zip = $this->open($path);

        try {
            $limits = (array) config('history-import.zip', []);
            $maxEntries = max(1, (int) ($limits['max_entries'] ?? 20000));
            $maxTotal = max(1, (int) ($limits['max_uncompressed_bytes'] ?? 4 * 1024 * 1024 * 1024));
            $maxEntry = max(1, (int) ($limits['max_entry_bytes'] ?? 100 * 1024 * 1024));
            $maxRatio = max(1, (int) ($limits['max_compression_ratio'] ?? 100));

            if ($zip->numFiles > $maxEntries) {
                $this->fail('El ZIP tiene más de '.number_format($maxEntries).' archivos. Divídelo en partes más pequeñas.');
            }

            $entries = [];
            $total = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);

                if ($stat === false) {
                    $this->fail('El ZIP está dañado y no puede leerse.');
                }

                $name = (string) $stat['name'];
                $size = (int) $stat['size'];
                $compressed = max(1, (int) $stat['comp_size']);

                if (! $this->isSafeName($name)) {
                    $this->fail('El ZIP contiene rutas no permitidas (por ejemplo «..» o rutas absolutas) y no puede importarse.');
                }

                if ($stat['encryption_method'] !== 0) {
                    $this->fail('El ZIP está protegido con contraseña. Súbelo sin contraseña.');
                }

                $mode = $this->unixMode($zip, $index);

                if ($mode !== null && ($mode & self::S_IFMT) === self::S_IFLNK) {
                    $this->fail('El ZIP contiene accesos directos (enlaces simbólicos) y no puede importarse.');
                }

                if (str_ends_with($name, '/') || ($mode !== null && ($mode & self::S_IFMT) === self::S_IFDIR)) {
                    continue;
                }

                if ($size > $maxEntry) {
                    $this->fail('Un archivo del ZIP supera el tamaño máximo permitido ('.self::megabytes($maxEntry).' MB).');
                }

                if ($size > 1024 * 1024 && $size / $compressed > $maxRatio) {
                    $this->fail('El ZIP tiene un contenido comprimido sospechoso y no puede importarse.');
                }

                $total += $size;

                if ($total > $maxTotal) {
                    $this->fail('El contenido del ZIP es demasiado grande. Divídelo en partes más pequeñas.');
                }

                $entries[] = ['index' => $index, 'name' => $name, 'base' => self::baseName($name), 'size' => $size];
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Copy one entry (checked by entries()) to a private temporary file.
     * Returns the path; the caller deletes it.
     *
     * @throws ValidationException When the entry is missing or larger than declared.
     */
    public function extract(string $path, int $index, int $declaredSize): string
    {
        $zip = $this->open($path);

        try {
            return $this->extractFrom($zip, $index, $declaredSize);
        } finally {
            $zip->close();
        }
    }

    /**
     * Same as extract() on an archive the caller keeps open (one open
     * archive per ZIP for a whole run instead of one per file).
     *
     * @throws ValidationException
     */
    public function extractFrom(ZipArchive $zip, int $index, int $declaredSize): string
    {
        $target = tempnam(sys_get_temp_dir(), 'hist');

        if ($target === false) {
            $this->fail('No se pudo preparar un archivo temporal.');
        }

        try {
            $source = $zip->getStreamIndex($index);

            if ($source === false) {
                $this->fail('No se pudo leer un archivo del ZIP.');
            }

            $out = fopen($target, 'wb');

            if ($out === false) {
                fclose($source);
                $this->fail('No se pudo preparar un archivo temporal.');
            }

            $written = stream_copy_to_stream($source, $out, $declaredSize + 1);
            fclose($source);
            fclose($out);

            if ($written === false || $written > $declaredSize) {
                $this->fail('Un archivo del ZIP es más grande de lo que declara y no puede importarse.');
            }

            return $target;
        } catch (ValidationException $exception) {
            @unlink($target);

            throw $exception;
        }
    }

    /**
     * Open a ZIP read-only (entries() must have accepted it).
     *
     * @throws ValidationException
     */
    public function openArchive(string $path): ZipArchive
    {
        return $this->open($path);
    }

    /**
     * Read one small entry as text (Chat.txt), capped at $maxBytes.
     *
     * @throws ValidationException
     */
    public function read(string $path, int $index, int $declaredSize, int $maxBytes): string
    {
        if ($declaredSize > $maxBytes) {
            $this->fail('El archivo de texto del chat es demasiado grande ('.self::megabytes($maxBytes).' MB como máximo).');
        }

        $temporary = $this->extract($path, $index, $declaredSize);

        try {
            return (string) file_get_contents($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Lowercase base name of an entry (how messages reference media).
     */
    public static function baseName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $base = basename($name);

        return mb_strtolower(trim($base));
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('El archivo no es un ZIP válido.');
        }

        return $zip;
    }

    /**
     * A relative path without traversal, drive letters, backslashes,
     * control characters or NUL bytes.
     */
    private function isSafeName(string $name): bool
    {
        if ($name === '' || strlen($name) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        if (str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Unix mode of an entry created on a Unix system, if any.
     */
    private function unixMode(ZipArchive $zip, int $index): ?int
    {
        $system = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $system, $attributes)) {
            return null;
        }

        return $system === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) & 0xFFFF : null;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.');
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
