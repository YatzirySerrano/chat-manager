<?php

namespace App\Services\Conversations;

use App\Enums\MessageType;
use Illuminate\Http\UploadedFile;

/**
 * What an agent may attach to a reply, mirroring the WhatsApp Cloud API
 * media limits. The real content type is detected from the file bytes
 * (finfo), never from the name or the browser's claim, so an executable
 * renamed "factura.pdf" is refused.
 *
 * Limits come from `config('whatsapp.media.types')` (messaging module) when present, as
 * `[type => ['mimes' => list<string>, 'max_bytes' => int]]`; otherwise the
 * Cloud API defaults below apply (documents capped lower than Meta's 100 MB).
 *
 * @phpstan-type MediaRule array{mimes: list<string>, max_bytes: int}
 */
class OutboundAttachmentRules
{
    /**
     * @var array<string, MediaRule>
     */
    private const DEFAULTS = [
        'image' => ['mimes' => ['image/jpeg', 'image/png'], 'max_bytes' => 5 * 1024 * 1024],
        'document' => ['mimes' => [
            'application/pdf',
            'text/plain',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], 'max_bytes' => 16 * 1024 * 1024],
        'audio' => ['mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'], 'max_bytes' => 16 * 1024 * 1024],
        'video' => ['mimes' => ['video/mp4', 'video/3gpp'], 'max_bytes' => 16 * 1024 * 1024],
    ];

    /**
     * Content types finfo reports for formats WhatsApp accepts under another name.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'audio/x-m4a' => 'audio/mp4',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/mp3' => 'audio/mpeg',
        'application/ogg' => 'audio/ogg',
        'video/3gp' => 'video/3gpp',
    ];

    /**
     * Generic containers finfo may report for Office files (OOXML is a zip,
     * legacy Office a compound file). Only the matching Office extension
     * turns them into the type WhatsApp accepts; any other zip or compound
     * file is refused, since the provider would reject it.
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
    ];

    /**
     * @return array<string, MediaRule>
     */
    public function rules(): array
    {
        $configured = config('whatsapp.media.types');
        $rules = self::DEFAULTS;

        if (! is_array($configured)) {
            return $rules;
        }

        foreach (array_keys(self::DEFAULTS) as $type) {
            $rule = $configured[$type] ?? null;

            if (! is_array($rule)) {
                continue;
            }

            if (isset($rule['mimes']) && is_array($rule['mimes']) && $rule['mimes'] !== []) {
                $rules[$type]['mimes'] = array_values(array_diff(array_map('strval', $rule['mimes']), array_keys(self::CONTAINERS)));
            }

            if (isset($rule['max_bytes']) && is_numeric($rule['max_bytes']) && (int) $rule['max_bytes'] > 0) {
                $rules[$type]['max_bytes'] = (int) $rule['max_bytes'];
            }
        }

        return $rules;
    }

    /**
     * The real content type of the uploaded file, read from its bytes.
     */
    public function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mime = $path === false ? false : (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = is_string($mime) ? strtolower($mime) : 'application/octet-stream';

        if (isset(self::CONTAINERS[$mime])) {
            return self::CONTAINERS[$mime][strtolower($file->getClientOriginalExtension())] ?? $mime;
        }

        return self::ALIASES[$mime] ?? $mime;
    }

    /**
     * The message type the file is sent as, or null when it is not allowed.
     */
    public function typeFor(string $mime): ?MessageType
    {
        if (isset(self::CONTAINERS[$mime])) {
            return null;
        }

        foreach ($this->rules() as $type => $rule) {
            if (in_array($mime, $rule['mimes'], true)) {
                return MessageType::from($type);
            }
        }

        return null;
    }

    /**
     * Why the file cannot be attached, or null when it can.
     */
    public function refusal(UploadedFile $file): ?string
    {
        $mime = $this->detectMime($file);
        $type = $this->typeFor($mime);

        if ($type === null) {
            return 'Este tipo de archivo no se puede enviar por WhatsApp. Adjunta una imagen JPG o PNG, un PDF o documento de Office, un audio o un video MP4.';
        }

        $limit = $this->rules()[$type->value]['max_bytes'];

        if ((int) $file->getSize() > $limit) {
            return sprintf('El archivo pesa más de %s, el máximo para %s.', $this->humanSize($limit), $this->typeLabel($type));
        }

        return null;
    }

    /**
     * Limits for the composer, so it can warn before uploading.
     *
     * @return list<array{type: string, label: string, mimes: list<string>, max_bytes: int}>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->rules() as $type => $rule) {
            $described[] = [
                'type' => $type,
                'label' => $this->typeLabel(MessageType::from($type)),
                'mimes' => $rule['mimes'],
                'max_bytes' => $rule['max_bytes'],
            ];
        }

        return $described;
    }

    private function typeLabel(MessageType $type): string
    {
        return match ($type) {
            MessageType::Image => 'imágenes',
            MessageType::Audio => 'audios',
            MessageType::Video => 'videos',
            default => 'documentos',
        };
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.').' MB'
            : (int) ceil($bytes / 1024).' KB';
    }
}
