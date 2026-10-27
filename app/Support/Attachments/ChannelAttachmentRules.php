<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\ConnectionChannel;
use Illuminate\Http\UploadedFile;

/**
 * What the composer may attach to a reply on one channel (see
 * ChannelAttachmentPolicy): the accepted kinds of files and the reason a
 * given file is refused. The file's real content type (finfo) decides its
 * kind; its name must carry an extension that matches that content, so a
 * zip archive renamed "contrato.pdf" or an executable renamed "foto.jpg" is
 * always refused.
 */
final readonly class ChannelAttachmentRules
{
    /**
     * @param  list<AttachmentKind>  $kinds
     * @param  bool  $oggRequiresOpus  The channel only plays Ogg audio encoded with Opus (WhatsApp).
     */
    public function __construct(
        public ConnectionChannel $channel,
        public array $kinds,
        public bool $oggRequiresOpus = false,
    ) {}

    public function kindFor(string $mime): ?AttachmentKind
    {
        foreach ($this->kinds as $kind) {
            if ($kind->covers($mime)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The largest file any kind of this channel accepts (the request cap).
     */
    public function maxBytes(): int
    {
        return max(0, ...array_map(fn (AttachmentKind $kind): int => $kind->maxBytes, $this->kinds));
    }

    /**
     * Why the file cannot be sent through this channel, or null when it can.
     */
    public function refusal(UploadedFile $file, string $mime): ?string
    {
        return $this->rejection($file, $mime)['message'] ?? null;
    }

    /**
     * Why the file cannot be sent through this channel, with a stable code
     * (unsupported_type, extension_mismatch, channel_size_limit or
     * unsupported_audio_codec), or null when it can.
     *
     * @return array{code: string, message: string}|null
     */
    public function rejection(UploadedFile $file, string $mime): ?array
    {
        $kind = $this->kindFor($mime);
        $channel = $this->channel->label();

        if ($kind === null) {
            return ['code' => 'unsupported_type', 'message' => sprintf('Este tipo de archivo no se puede enviar por %s. Puedes adjuntar %s.', $channel, $this->acceptedSummary())];
        }

        if (! $kind->acceptsExtension($mime, $file->getClientOriginalExtension())) {
            return ['code' => 'extension_mismatch', 'message' => sprintf(
                'El archivo no se puede enviar: su extensión «.%s» no corresponde a su contenido real (%s). Revisa que sea el archivo correcto.',
                strtolower($file->getClientOriginalExtension()),
                strtoupper((string) $kind->canonicalExtension($mime)),
            )];
        }

        if ((int) $file->getSize() > $kind->maxBytes) {
            return ['code' => 'channel_size_limit', 'message' => sprintf(
                '%s supera el tamaño permitido para %s (%s): pesa %s. Envía un archivo más pequeño.',
                self::subjectOf($kind),
                $kind->delivery === AttachmentDeliveryMethod::Link ? "enviarlo como enlace privado por {$channel}" : $channel,
                self::humanSize($kind->maxBytes),
                self::weight((int) $file->getSize(), $kind->maxBytes),
            )];
        }

        if ($this->oggRequiresOpus && $mime === 'audio/ogg' && ! self::isMonoOpus($file)) {
            return ['code' => 'unsupported_audio_codec', 'message' => sprintf('%s solo acepta audios OGG codificados con Opus en mono. Convierte el audio o adjúntalo como MP3 o M4A.', $channel)];
        }

        return null;
    }

    /**
     * The size of a file over a limit, never shown equal to the limit
     * ("más de 5 MB" when both round to 5 MB).
     */
    private static function weight(int $bytes, int $limit): string
    {
        $size = self::humanSize($bytes);

        return $size === self::humanSize($limit) ? "más de {$size}" : $size;
    }

    /**
     * "Esta imagen", "Este video"... to start a sentence about the file.
     */
    private static function subjectOf(AttachmentKind $kind): string
    {
        return match ($kind->key) {
            'image' => 'Esta imagen',
            'video' => 'Este video',
            'audio' => 'Este audio',
            'archive' => 'Este archivo ZIP',
            default => 'Este documento',
        };
    }

    /**
     * Limits for the composer, so it can warn before uploading.
     *
     * @return array{channel: string, channel_label: string, max_bytes: int, link_ttl_days: int, kinds: list<array{key: string, type: string, label: string, mimes: list<string>, extensions: list<string>, max_bytes: int, caption: bool, delivery: string}>}
     */
    public function describe(): array
    {
        return [
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'max_bytes' => $this->maxBytes(),
            'link_ttl_days' => max(1, (int) config('attachments.link_ttl_days', 7)),
            'kinds' => array_map(fn (AttachmentKind $kind): array => $kind->toArray(), $this->kinds),
        ];
    }

    /**
     * "imágenes (JPG, PNG), documentos (PDF, DOCX) o audios (MP3)".
     */
    private function acceptedSummary(): string
    {
        $parts = array_map(
            fn (AttachmentKind $kind): string => sprintf(
                '%s (%s)%s',
                $kind->label,
                implode(', ', array_map('strtoupper', $kind->allExtensions())),
                $kind->delivery === AttachmentDeliveryMethod::Link ? ' como enlace de descarga' : '',
            ),
            $this->kinds,
        );

        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' o '.$last;
    }

    /**
     * Whether an Ogg file carries an Opus mono stream: its first page holds
     * the "OpusHead" identification header, whose channel count is 1.
     */
    public static function isMonoOpus(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        $handle = $path === false ? false : fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        $position = strpos($head, 'OpusHead');

        return str_starts_with($head, 'OggS') && $position !== false && isset($head[$position + 9]) && ord($head[$position + 9]) === 1;
    }

    /**
     * A size in decimal units (1 MB = 10^6 bytes), the unit of the channel
     * limits (ChannelAttachmentPolicy) and of the sizes macOS shows.
     */
    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1_000_000
            ? rtrim(rtrim(number_format($bytes / 1_000_000, 1, '.', ''), '0'), '.').' MB'
            : max(1, (int) round($bytes / 1000)).' KB';
    }
}
