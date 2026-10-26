<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\ConnectionChannel;
use App\Enums\MessageType;
use App\Services\Storage\MediaDisk;
use Illuminate\Http\UploadedFile;

/**
 * Per channel attachment policy of the composer, with each provider's
 * documented limits:
 *
 * - WhatsApp Cloud API: images JPEG/PNG 5 MB; audio AAC, AMR, MP3, M4A and
 *   OGG (Opus mono only) 16 MB, without caption; video MP4/3GPP 16 MB;
 *   documents PDF, TXT and Office 100 MB (`whatsapp.media.types`). WhatsApp
 *   has no ZIP type: archives are sent as a secure expiring download link
 *   when `whatsapp.media.link_fallback` is on. Stickers are never sent from
 *   the composer.
 * - Web chat (files hosted by this platform): images, PDF, Office, TXT/CSV,
 *   ZIP, audio and video natively, up to `attachments.web_chat.max_bytes`.
 * - Messenger: images JPEG/PNG/GIF 8 MB; audio, video and files 25 MB; ZIP
 *   as download link. Instagram: images PNG/JPEG 8 MB; audio AAC/M4A/WAV and
 *   video MP4/OGG/AVI/MOV/WEBM 25 MB; files PDF only, 25 MB; ZIP as download
 *   link. Neither carries text with a file.
 *
 * The real content type is read from the file's bytes (finfo), never from
 * its name or the browser's claim.
 */
class ChannelAttachmentPolicy
{
    private const MB = 1024 * 1024;

    /** Attachment menu categories, in menu order (as in WhatsApp). */
    private const CATEGORIES = ['image', 'video', 'document', 'audio'];

    /**
     * Extensions a file of each real content type may carry (first = canonical).
     *
     * @var array<string, list<string>>
     */
    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'audio/aac' => ['aac'],
        'audio/amr' => ['amr'],
        'audio/mpeg' => ['mp3'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/ogg' => ['ogg', 'opus', 'oga'],
        'audio/wav' => ['wav'],
        'audio/webm' => ['weba', 'webm'],
        'video/mp4' => ['mp4', 'm4v'],
        'video/3gpp' => ['3gp', '3gpp'],
        'video/quicktime' => ['mov'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv', 'ogg'],
        'video/x-msvideo' => ['avi'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'log'],
        'text/csv' => ['csv'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/zip' => ['zip'],
    ];

    private const OFFICE = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * Content types finfo reports for formats accepted under another name.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'audio/x-m4a' => 'audio/mp4',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/mp3' => 'audio/mpeg',
        'audio/opus' => 'audio/ogg',
        'application/ogg' => 'audio/ogg',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'video/3gp' => 'video/3gpp',
        'video/avi' => 'video/x-msvideo',
        'video/msvideo' => 'video/x-msvideo',
        'image/jpg' => 'image/jpeg',
    ];

    /**
     * Generic containers finfo may report for specific formats (OOXML is a
     * zip, legacy Office a compound file, M4A an MP4). Only the matching
     * extension turns them into that format; any other compound file stays
     * unaccepted, and a zip with another extension stays a plain archive.
     *
     * @var array<string, array<string, string>>
     */
    private const CONTAINERS = [
        'application/zip' => [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        'application/vnd.ms-office' => [
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
        ],
        'application/x-ole-storage' => [
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
        ],
        'application/cdfv2' => [
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
        ],
        'video/mp4' => [
            'm4a' => 'audio/mp4',
        ],
    ];

    public function forChannel(ConnectionChannel $channel): ChannelAttachmentRules
    {
        return match ($channel) {
            ConnectionChannel::Whatsapp => $this->whatsapp(),
            ConnectionChannel::WebChat => $this->webChat(),
            ConnectionChannel::Messenger => $this->messenger(),
            ConnectionChannel::Instagram => $this->instagram(),
        };
    }

    /**
     * The largest file any channel accepts (the absolute upload cap).
     */
    public function hardCapBytes(): int
    {
        return max(array_map(fn (ConnectionChannel $channel): int => $this->forChannel($channel)->maxBytes(), ConnectionChannel::cases()));
    }

    /**
     * The composer limits of every channel, keyed by channel, for the
     * attachment menu: each kind carries its menu `category` (image, video,
     * audio or document; ZIP archives are documents sent as a link), the
     * channel lists its `categories` (accept hint, size limit and how they
     * are delivered) and `storage` says whether files can be stored now.
     *
     * @return array<string, array{channel: string, channel_label: string, max_bytes: int, link_ttl_days: int, kinds: list<array<string, mixed>>, categories: list<array{key: string, kinds: list<string>, mimes: list<string>, extensions: list<string>, accept: string, max_bytes: int, delivery: string}>, storage: array{ready: bool, message: string|null}}>
     */
    public function describeAll(): array
    {
        $mediaDisk = app(MediaDisk::class);
        $storage = $mediaDisk->ready()
            ? ['ready' => true, 'message' => null]
            : ['ready' => false, 'message' => 'Por ahora no se pueden adjuntar archivos: el almacenamiento de archivos no está disponible. Puedes enviar mensajes de texto; avisa a un administrador.'];
        $described = [];

        foreach (ConnectionChannel::cases() as $channel) {
            $rules = $this->forChannel($channel);
            $description = $rules->describe();
            $description['kinds'] = array_map(
                fn (array $kind): array => [...$kind, 'category' => self::categoryOf($kind['type'])],
                $description['kinds'],
            );
            $description['categories'] = $this->categories($rules);
            $description['storage'] = $storage;
            $described[$channel->value] = $description;
        }

        return $described;
    }

    /**
     * Menu category of a message type (a ZIP archive is a document).
     */
    public static function categoryOf(string $type): string
    {
        return in_array($type, self::CATEGORIES, true) ? $type : 'document';
    }

    /**
     * The categories the channel can send, in menu order: kind keys, real
     * types, extensions, the file chooser's `accept` hint, the largest size
     * and the delivery ("native", "link", or "mixed" when part of the
     * category travels as a download link, e.g. ZIP among documents).
     *
     * @return list<array{key: string, kinds: list<string>, mimes: list<string>, extensions: list<string>, accept: string, max_bytes: int, delivery: string}>
     */
    private function categories(ChannelAttachmentRules $rules): array
    {
        $categories = [];

        foreach (self::CATEGORIES as $category) {
            $kinds = array_values(array_filter($rules->kinds, fn (AttachmentKind $kind): bool => self::categoryOf($kind->type->value) === $category));

            if ($kinds === []) {
                continue;
            }

            $mimes = array_values(array_unique(array_merge(...array_map(fn (AttachmentKind $kind): array => $kind->mimes(), $kinds))));
            $extensions = array_values(array_unique(array_merge(...array_map(fn (AttachmentKind $kind): array => $kind->allExtensions(), $kinds))));
            $deliveries = array_values(array_unique(array_map(fn (AttachmentKind $kind): string => $kind->delivery->value, $kinds)));

            $categories[] = [
                'key' => $category,
                'kinds' => array_map(fn (AttachmentKind $kind): string => $kind->key, $kinds),
                'mimes' => $mimes,
                'extensions' => $extensions,
                'accept' => implode(',', [...$mimes, ...array_map(fn (string $extension): string => '.'.$extension, $extensions)]),
                'max_bytes' => max(array_map(fn (AttachmentKind $kind): int => $kind->maxBytes, $kinds)),
                'delivery' => count($deliveries) === 1 ? $deliveries[0] : 'mixed',
            ];
        }

        return $categories;
    }

    /**
     * The real content type of the uploaded file, read from its bytes.
     */
    public function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mime = $path === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = is_string($mime) ? strtolower($mime) : 'application/octet-stream';
        $mime = self::ALIASES[$mime] ?? $mime;

        if (isset(self::CONTAINERS[$mime])) {
            return self::CONTAINERS[$mime][strtolower($file->getClientOriginalExtension())] ?? $mime;
        }

        return $mime;
    }

    private function whatsapp(): ChannelAttachmentRules
    {
        $configured = config('whatsapp.media.types');
        $configured = is_array($configured) ? $configured : [];

        $kind = function (string $key, MessageType $type, string $label, array $defaultMimes, int $defaultMax, bool $caption) use ($configured): AttachmentKind {
            $rule = is_array($configured[$key] ?? null) ? $configured[$key] : [];
            // Archives, the generic containers inbound media may be reported
            // as and Ogg video are never sent natively from the composer.
            $mimes = isset($rule['mimes']) && is_array($rule['mimes']) && $rule['mimes'] !== []
                ? array_diff(array_map('strval', $rule['mimes']), ['application/zip', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2', 'video/ogg'])
                : $defaultMimes;
            $max = isset($rule['max_bytes']) && is_numeric($rule['max_bytes']) && (int) $rule['max_bytes'] > 0 ? (int) $rule['max_bytes'] : $defaultMax;

            return new AttachmentKind($key, $type, $label, $this->extensionsFor($mimes), $max, $caption);
        };

        $kinds = [
            $kind('image', MessageType::Image, 'imágenes', ['image/jpeg', 'image/png'], 5 * self::MB, true),
            $kind('document', MessageType::Document, 'documentos', ['application/pdf', 'text/plain', ...self::OFFICE], 100 * self::MB, true),
            $kind('audio', MessageType::Audio, 'audios', ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'], 16 * self::MB, false),
            $kind('video', MessageType::Video, 'videos', ['video/mp4', 'video/3gpp'], 16 * self::MB, true),
        ];

        if ((bool) config('whatsapp.media.link_fallback', true)) {
            $kinds[] = $this->archiveLink();
        }

        return new ChannelAttachmentRules(ConnectionChannel::Whatsapp, $kinds, oggRequiresOpus: true);
    }

    private function webChat(): ChannelAttachmentRules
    {
        $max = (int) config('attachments.web_chat.max_bytes', 25 * self::MB);
        $max = $max > 0 ? $max : 25 * self::MB;

        return new ChannelAttachmentRules(ConnectionChannel::WebChat, [
            new AttachmentKind('image', MessageType::Image, 'imágenes', $this->extensionsFor(['image/jpeg', 'image/png', 'image/webp', 'image/gif']), $max, true),
            new AttachmentKind('document', MessageType::Document, 'documentos', $this->extensionsFor(['application/pdf', 'text/plain', 'text/csv', ...self::OFFICE]), $max, true),
            new AttachmentKind('archive', MessageType::Document, 'archivos ZIP', $this->extensionsFor(['application/zip']), $max, true),
            new AttachmentKind('audio', MessageType::Audio, 'audios', $this->extensionsFor(['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/webm']), $max, true),
            new AttachmentKind('video', MessageType::Video, 'videos', $this->extensionsFor(['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg']), $max, true),
        ]);
    }

    private function messenger(): ChannelAttachmentRules
    {
        $kinds = [
            new AttachmentKind('image', MessageType::Image, 'imágenes', $this->extensionsFor(['image/jpeg', 'image/png', 'image/gif']), 8 * self::MB, false),
            new AttachmentKind('document', MessageType::Document, 'documentos', $this->extensionsFor(['application/pdf', 'text/plain', 'text/csv', ...self::OFFICE]), 25 * self::MB, false),
            new AttachmentKind('audio', MessageType::Audio, 'audios', $this->extensionsFor(['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/ogg']), 25 * self::MB, false),
            new AttachmentKind('video', MessageType::Video, 'videos', $this->extensionsFor(['video/mp4', 'video/quicktime']), 25 * self::MB, false),
        ];

        if ((bool) config('attachments.link_fallback.messenger', true)) {
            $kinds[] = $this->archiveLink();
        }

        return new ChannelAttachmentRules(ConnectionChannel::Messenger, $kinds);
    }

    private function instagram(): ChannelAttachmentRules
    {
        $kinds = [
            new AttachmentKind('image', MessageType::Image, 'imágenes', $this->extensionsFor(['image/png', 'image/jpeg']), 8 * self::MB, false),
            new AttachmentKind('document', MessageType::Document, 'documentos PDF', $this->extensionsFor(['application/pdf']), 25 * self::MB, false),
            new AttachmentKind('audio', MessageType::Audio, 'audios', $this->extensionsFor(['audio/aac', 'audio/mp4', 'audio/wav']), 25 * self::MB, false),
            new AttachmentKind('video', MessageType::Video, 'videos', $this->extensionsFor(['video/mp4', 'video/ogg', 'video/x-msvideo', 'video/quicktime', 'video/webm']), 25 * self::MB, false),
        ];

        if ((bool) config('attachments.link_fallback.instagram', true)) {
            $kinds[] = $this->archiveLink();
        }

        return new ChannelAttachmentRules(ConnectionChannel::Instagram, $kinds);
    }

    /**
     * ZIP archives on channels without a native type for them: kept on the
     * private disk and sent as a text with a signed, expiring download link.
     */
    private function archiveLink(): AttachmentKind
    {
        $max = (int) config('attachments.link_max_bytes', 100 * self::MB);

        return new AttachmentKind(
            'archive',
            MessageType::Document,
            'archivos ZIP',
            $this->extensionsFor(['application/zip']),
            $max > 0 ? $max : 100 * self::MB,
            true,
            AttachmentDeliveryMethod::Link,
        );
    }

    /**
     * The known extensions of the given content types; types that are not
     * real formats (generic containers, legacy aliases) are dropped.
     *
     * @param  array<int, string>  $mimes
     * @return array<string, list<string>>
     */
    private function extensionsFor(array $mimes): array
    {
        $extensions = [];

        foreach ($mimes as $mime) {
            $mime = self::ALIASES[strtolower($mime)] ?? strtolower($mime);

            if (isset(self::EXTENSIONS[$mime])) {
                $extensions[$mime] = self::EXTENSIONS[$mime];
            }
        }

        return $extensions;
    }
}
