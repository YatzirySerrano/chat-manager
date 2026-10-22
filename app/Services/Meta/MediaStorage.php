<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates a customer media file and stores it on the private media disk
 * (config filesystems.media_disk) under media/{yyyy}/{mm}/{uuid}. The type
 * is detected from the content (finfo), never trusted from Meta or the
 * file name; sizes and types follow config/whatsapp.php.
 */
class MediaStorage
{
    /**
     * Detected types that are aliases of an accepted one.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'application/ogg' => 'audio/ogg',
        'audio/x-m4a' => 'audio/mp4',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/x-aac' => 'audio/aac',
        'audio/mp3' => 'audio/mpeg',
        'image/jpg' => 'image/jpeg',
        'audio/amr-wb' => 'audio/amr',
    ];

    /**
     * Validate and store a local file.
     *
     * @return array{disk: string, path: string, mime_type: string, size_bytes: int, sha256: string}
     *
     * @throws MediaRejected
     */
    public function store(string $localPath, string $kind): array
    {
        $size = is_file($localPath) ? (int) filesize($localPath) : 0;

        if ($size <= 0) {
            throw new MediaRejected('empty', 'El archivo recibido está vacío.');
        }

        $this->assertSize($kind, $size);
        $mime = $this->detect($localPath);
        $this->assertType($kind, $mime);

        $disk = (string) config('filesystems.media_disk', 'local');
        $path = 'media/'.now()->format('Y').'/'.now()->format('m').'/'.Str::uuid();
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new MediaRejected('unreadable', 'No fue posible leer el archivo recibido.');
        }

        try {
            Storage::disk($disk)->writeStream($path, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => (string) hash_file('sha256', $localPath),
        ];
    }

    /**
     * @throws MediaRejected
     */
    public function assertSize(string $kind, int $size): void
    {
        $max = $this->rules($kind)['max_bytes'];

        if ($size > $max) {
            throw new MediaRejected('too_large', 'El archivo supera el tamaño permitido ('.self::megabytes($max).' MB).');
        }
    }

    /**
     * @throws MediaRejected
     */
    public function assertType(string $kind, string $mime): void
    {
        if (! in_array($mime, $this->rules($kind)['mimes'], true)) {
            throw new MediaRejected('type_not_allowed', "El tipo de archivo ({$mime}) no está permitido para este mensaje.");
        }
    }

    public function detect(string $localPath): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        return self::ALIASES[$mime] ?? $mime;
    }

    /**
     * @return array{mimes: list<string>, max_bytes: int}
     */
    public function rules(string $kind): array
    {
        $rules = config("whatsapp.media.types.{$kind}");

        if (! is_array($rules)) {
            throw new MediaRejected('type_not_allowed', 'Este tipo de mensaje no admite archivos.');
        }

        return [
            'mimes' => array_values(array_filter((array) ($rules['mimes'] ?? []), 'is_string')),
            'max_bytes' => max(1, (int) ($rules['max_bytes'] ?? 0)),
        ];
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.');
    }
}
