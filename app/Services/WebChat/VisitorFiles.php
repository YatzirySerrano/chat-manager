<?php

namespace App\Services\WebChat;

use App\Enums\MessageType;
use App\Support\Attachments\ChannelAttachmentPolicy;
use App\Support\Attachments\ServerUploadLimits;
use Illuminate\Http\UploadedFile;

/**
 * What a web chat visitor may send from the widget (config
 * webchat.visitor_files): images, PDF, Office, TXT/CSV, audio and video,
 * never archives, scripts or executables. The real type is read from the
 * file's bytes (ChannelAttachmentPolicy::detectMime, finfo); the name or
 * the browser's claim only ever tell an audio apart from a video in a
 * container both use (MP4, WebM, Ogg). Every kind shares one size limit
 * except videos, which have their own larger cap; the effective limits never
 * exceed what this server's PHP accepts (ServerUploadLimits).
 */
class VisitorFiles
{
    /** Decimal megabytes, like the composer's limits. */
    private const MB = 1_000_000;

    /**
     * Accepted real types per message kind.
     *
     * @var array<string, list<string>>
     */
    public const TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg', 'video/3gpp'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/webm'],
        'document' => [
            'application/pdf',
            'text/plain',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    /**
     * Extensions the file chooser offers (a hint only: the server reads the bytes).
     *
     * @var list<string>
     */
    private const EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        'mp4', 'm4v', 'webm', 'mov', 'ogv', '3gp',
        'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'weba',
        'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    /** Extensions that mark an audio inside a container finfo reports as video. */
    private const AUDIO_EXTENSIONS = ['m4a', 'aac', 'mp3', 'oga', 'ogg', 'opus', 'wav', 'weba'];

    public function __construct(private ChannelAttachmentPolicy $policy, private ServerUploadLimits $server) {}

    /**
     * Largest file of any kind but video, after the server's own limit.
     */
    public function maxBytes(): int
    {
        return $this->capped((int) config('webchat.visitor_files.max_bytes', 25 * self::MB));
    }

    /**
     * Largest video, after the server's own limit.
     */
    public function videoMaxBytes(): int
    {
        return $this->capped((int) config('webchat.visitor_files.video_max_bytes', 64 * self::MB));
    }

    public function maxBytesFor(string $kind): int
    {
        return $kind === 'video' ? $this->videoMaxBytes() : $this->maxBytes();
    }

    /**
     * The largest upload of any kind (the request's absolute cap).
     */
    public function hardCapBytes(): int
    {
        return max($this->maxBytes(), $this->videoMaxBytes());
    }

    /**
     * The real type of the file (aliases resolved), read from its bytes.
     */
    public function detect(UploadedFile $file): string
    {
        $mime = $this->policy->detectMime($file);

        // An audio in an MP4, WebM or Ogg container is detected as video:
        // only then the name or the browser's claim decide it is an audio.
        if (in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'], true)) {
            $extension = strtolower($file->getClientOriginalExtension());
            $claimed = strtolower((string) $file->getClientMimeType());

            if (in_array($extension, self::AUDIO_EXTENSIONS, true) || str_starts_with($claimed, 'audio/')) {
                return 'audio/'.substr($mime, 6);
            }
        }

        return $mime;
    }

    /**
     * Message kind of an accepted real type, or null when it is not accepted.
     */
    public function kindOf(string $mime): ?string
    {
        foreach (self::TYPES as $kind => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return $kind;
            }
        }

        return null;
    }

    public static function messageType(string $kind): MessageType
    {
        return match ($kind) {
            'image' => MessageType::Image,
            'video' => MessageType::Video,
            'audio' => MessageType::Audio,
            default => MessageType::Document,
        };
    }

    /**
     * The kind and real type of an acceptable file, or why it cannot be
     * sent (Spanish, for the visitor).
     *
     * @return array{kind: string, mime: string}|string
     */
    public function inspect(UploadedFile $file): array|string
    {
        $mime = $this->detect($file);
        $kind = $this->kindOf($mime);

        if ($kind === null) {
            return 'Este tipo de archivo no se puede enviar. Envía una imagen, un video, un audio, un PDF o un documento de Office.';
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            return 'El archivo está vacío.';
        }

        $max = $this->maxBytesFor($kind);

        if ($size > $max) {
            return ($kind === 'video' ? 'El video' : 'El archivo').' supera el tamaño permitido ('.self::megabytes($max).' MB).';
        }

        return ['kind' => $kind, 'mime' => $mime];
    }

    /**
     * What the widget needs to check a file before uploading it, and what
     * the connection form tells the administrator.
     *
     * @return array{accept: string, max_bytes: int, video_max_bytes: int, max_mb: string, video_max_mb: string}
     */
    public function summary(): array
    {
        $mimes = array_merge(...array_values(self::TYPES));

        return [
            'accept' => implode(',', [...$mimes, ...array_map(fn (string $extension): string => '.'.$extension, self::EXTENSIONS)]),
            'max_bytes' => $this->maxBytes(),
            'video_max_bytes' => $this->videoMaxBytes(),
            'max_mb' => self::megabytes($this->maxBytes()),
            'video_max_mb' => self::megabytes($this->videoMaxBytes()),
        ];
    }

    private function capped(int $configured): int
    {
        $configured = $configured > 0 ? $configured : 25 * self::MB;
        $server = $this->server->maxFileBytes();

        return $server !== null ? min($configured, $server) : $configured;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / self::MB, 1), '0'), '.');
    }
}
