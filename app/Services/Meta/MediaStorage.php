<?php

namespace App\Services\Meta;

use App\Enums\ConnectionChannel;
use App\Exceptions\StorageUnavailable;
use App\Services\Storage\MediaDisk;

/**
 * Validates a customer media file and stores it on the private media disk
 * (config filesystems.media_disk) under media/{yyyy}/{mm}/{uuid}. The type
 * is detected from the content (finfo), never trusted from Meta or the
 * file name. Sizes and types follow config/whatsapp.php for WhatsApp and
 * the channel's own limits for Messenger and Instagram (self::META_INBOUND):
 * images up to 8 MB (GIF and WebP included, so stickers and "Like" thumbs
 * are kept), audio, video and files up to 25 MB.
 *
 * The write goes through MediaDisk: it is verified (result, existence and
 * size) and an unready or failing disk raises StorageUnavailable instead of
 * returning a path that does not exist.
 */
class MediaStorage
{
    public function __construct(private MediaDisk $disk) {}

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
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'video/avi' => 'video/x-msvideo',
        'video/msvideo' => 'video/x-msvideo',
        'video/3gp' => 'video/3gpp',
    ];

    private const MB = 1024 * 1024;

    private const OFFICE = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-office',
        'application/zip',
    ];

    /**
     * Media customers may send through Messenger and Instagram, per message
     * kind (Meta's attachment limit is 25 MB; images 8 MB).
     *
     * @var array<string, array<string, array{mimes: list<string>, max_bytes: int}>>
     */
    private const META_INBOUND = [
        'messenger' => [
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'max_bytes' => 8 * self::MB],
            'sticker' => ['mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'max_bytes' => 8 * self::MB],
            'audio' => ['mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm', 'video/mp4', 'video/webm'], 'max_bytes' => 25 * self::MB],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp', 'video/ogg', 'video/x-msvideo'], 'max_bytes' => 25 * self::MB],
            'document' => ['mimes' => ['application/pdf', 'text/plain', 'text/csv', ...self::OFFICE], 'max_bytes' => 25 * self::MB],
        ],
        'instagram' => [
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'max_bytes' => 8 * self::MB],
            'sticker' => ['mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 'max_bytes' => 8 * self::MB],
            'audio' => ['mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm', 'video/mp4', 'video/webm'], 'max_bytes' => 25 * self::MB],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/ogg', 'video/x-msvideo'], 'max_bytes' => 25 * self::MB],
            'document' => ['mimes' => ['application/pdf'], 'max_bytes' => 25 * self::MB],
        ],
    ];

    /**
     * Validate and store a local file.
     *
     * @return array{disk: string, path: string, mime_type: string, size_bytes: int, sha256: string}
     *
     * @throws MediaRejected
     * @throws StorageUnavailable The media disk is not ready or the write could not be verified; nothing was kept.
     */
    public function store(string $localPath, string $kind, ?ConnectionChannel $channel = null): array
    {
        $size = is_file($localPath) ? (int) filesize($localPath) : 0;

        if ($size <= 0) {
            throw new MediaRejected('empty', 'El archivo recibido está vacío.');
        }

        $this->assertSize($kind, $size, $channel);
        $mime = $this->detect($localPath);
        $this->assertType($kind, $mime, $channel);

        $stored = $this->disk->putFile($localPath, 'media/'.now()->format('Y').'/'.now()->format('m'));

        return [
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => (string) hash_file('sha256', $localPath),
        ];
    }

    /**
     * @throws MediaRejected
     */
    public function assertSize(string $kind, int $size, ?ConnectionChannel $channel = null): void
    {
        $max = $this->rules($kind, $channel)['max_bytes'];

        if ($size > $max) {
            throw new MediaRejected('too_large', 'El archivo supera el tamaño permitido ('.self::megabytes($max).' MB).');
        }
    }

    /**
     * @throws MediaRejected
     */
    public function assertType(string $kind, string $mime, ?ConnectionChannel $channel = null): void
    {
        if (! in_array($mime, $this->rules($kind, $channel)['mimes'], true)) {
            throw new MediaRejected('type_not_allowed', "El tipo de archivo ({$mime}) no está permitido para este mensaje.");
        }
    }

    public function detect(string $localPath): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);

        return self::ALIASES[$mime] ?? $mime;
    }

    /**
     * Accepted types and size of a kind of inbound media on the channel
     * (WhatsApp's table when no Meta messaging channel is given).
     *
     * @return array{mimes: list<string>, max_bytes: int}
     */
    public function rules(string $kind, ?ConnectionChannel $channel = null): array
    {
        $rules = $channel !== null && isset(self::META_INBOUND[$channel->value])
            ? (self::META_INBOUND[$channel->value][$kind] ?? null)
            : config("whatsapp.media.types.{$kind}");

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
