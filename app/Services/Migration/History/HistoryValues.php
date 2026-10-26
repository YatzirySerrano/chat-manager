<?php

namespace App\Services\Migration\History;

use App\Enums\MessageType;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Normalization of the values read from Whaticket and WhatsApp exports:
 * dates (ISO 8601, "Y-m-d H:i:s", day-first "d/m/Y H:i", Excel serial
 * numbers) read in the time zone of the source, yes/no flags, numbers and
 * chat identifiers ("5215500000001@c.us"), message types and text, which
 * is always kept as plain text (HTML tags removed, control characters
 * dropped, length capped). Nothing here is executed or evaluated.
 */
class HistoryValues
{
    /**
     * @var list<string>
     */
    private const TRUTHY = ['1', 'true', 't', 'yes', 'y', 'si', 'sí', 'verdadero', 'outbound', 'out', 'sent', 'enviado', 'saliente', 'me', 'yo'];

    /**
     * @var list<string>
     */
    private const FALSY = ['0', 'false', 'f', 'no', 'n', 'falso', 'inbound', 'in', 'received', 'recibido', 'entrante'];

    /**
     * Date formats tried in order (day first, as Mexican exports use).
     *
     * @var list<string>
     */
    private const FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d',
        // Two-digit years first: "Y" would read "26" as the year 0026.
        'd/m/y H:i:s', 'd/m/y H:i', 'd/m/y, H:i:s', 'd/m/y, H:i', 'd/m/y',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y, H:i:s', 'd/m/Y, H:i', 'd/m/Y',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y',
    ];

    /**
     * A date of the source as UTC, or null when it cannot be read reliably.
     */
    public function date(?string $value, string $timezone, ?string $time = null): ?CarbonImmutable
    {
        $date = $this->parseDate($value, $timezone, $time);

        // Outside what a TIMESTAMP column stores (and what a chat history can
        // be): a typo, never silently written as 0000-00-00.
        return $date !== null && $date->year >= 1971 && $date->year <= 2037 ? $date : null;
    }

    private function parseDate(?string $value, string $timezone, ?string $time): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($time !== null && trim($time) !== '' && ! str_contains($value, ':')) {
            $value .= ' '.trim($time);
        }

        // Excel serial date (days since 1899-12-30), as raw .xlsx cells give it.
        if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1) {
            $seconds = (int) round(((float) $value - 25569) * 86400);

            return $seconds > 0 ? CarbonImmutable::createFromTimestampUTC($seconds)->shiftTimezone($timezone)->utc() : null;
        }

        // Unix timestamps in seconds or milliseconds.
        if (preg_match('/^\d{10}(\d{3})?$/', $value) === 1) {
            $seconds = strlen($value) === 13 ? intdiv((int) $value, 1000) : (int) $value;

            return CarbonImmutable::createFromTimestampUTC($seconds);
        }

        // ISO 8601 with an explicit offset or Z.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                return null;
            }
        }

        $value = (string) preg_replace('/\.\d+$/', '', $value);
        $value = $this->twentyFourHour($value);

        foreach (self::FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone($timezone));
            $errors = \DateTimeImmutable::getLastErrors();

            // Rejects partial matches and overflows ("31/02/2026").
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return CarbonImmutable::instance($date)->utc();
            }
        }

        return null;
    }

    /**
     * "2:05 p. m." / "2:05 PM" written as "14:05".
     */
    public function twentyFourHour(string $value): string
    {
        if (preg_match('/^(.*?)(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap])\.?\s*m\.?$/iu', trim(str_replace("\u{202F}", ' ', $value)), $matches) !== 1) {
            return $value;
        }

        $hour = (int) $matches[2] % 12 + (strtolower($matches[5]) === 'p' ? 12 : 0);
        $seconds = $matches[4] !== '' ? ':'.$matches[4] : '';

        return trim($matches[1]).' '.sprintf('%02d', $hour).':'.$matches[3].$seconds;
    }

    public function flag(?string $value): ?bool
    {
        $value = mb_strtolower(trim((string) $value));

        return match (true) {
            in_array($value, self::TRUTHY, true) => true,
            in_array($value, self::FALSY, true) => false,
            default => null,
        };
    }

    /**
     * The identifier as written (trimmed, capped) for the record.
     */
    public function identifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 128, '');
    }

    /**
     * Whether the identifier is a group chat ("...@g.us").
     */
    public function isGroupIdentifier(?string $value): bool
    {
        return str_ends_with(mb_strtolower(trim((string) $value)), '@g.us');
    }

    /**
     * E.164 number of a phone or WhatsApp chat identifier, or null. Numbers
     * with 11+ digits are taken as international (Whaticket stores them
     * with the country code and without "+"); "521" becomes "52".
     */
    public function phone(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $this->isGroupIdentifier($value)) {
            return null;
        }

        $value = (string) preg_replace('/@(c\.us|s\.whatsapp\.net|lid)$/i', '', $value);
        $digits = (string) preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        $candidate = str_starts_with(trim($value), '+') || str_starts_with(trim($value), '00') || strlen($digits) >= 11 ? '+'.ltrim($digits, '0') : $digits;

        return PhoneNumber::normalize($candidate);
    }

    /**
     * Plain text of a message: tags removed when it looks like HTML,
     * control characters (except new lines and tabs) dropped, length capped.
     *
     * @return array{text: string|null, truncated: bool}
     */
    public function body(?string $value): array
    {
        $value = (string) $value;

        if ($value === '') {
            return ['text' => null, 'truncated' => false];
        }

        if (preg_match('/<\s*\/?\s*[a-z][a-z0-9]*(\s[^>]*)?>/i', $value) === 1) {
            $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);
        $max = max(100, (int) config('history-import.max_body_characters', 10000));
        $truncated = mb_strlen($value) > $max;

        return ['text' => $value === '' ? null : ($truncated ? mb_substr($value, 0, $max) : $value), 'truncated' => $truncated];
    }

    /**
     * Plain short text (names, departments, agents), or null.
     */
    public function label(?string $value, int $length = 160): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /**
     * Message type of a Whaticket media type or of a file name.
     */
    public function type(?string $mediaType, ?string $mediaName): MessageType
    {
        $type = mb_strtolower(trim((string) $mediaType));

        $fromType = match (true) {
            $type === '' => null,
            in_array($type, ['chat', 'text', 'texto', 'conversation', 'extendedtextmessage'], true) => MessageType::Text,
            str_contains($type, 'image') || $type === 'imagen' => MessageType::Image,
            str_contains($type, 'sticker') => MessageType::Sticker,
            str_contains($type, 'audio') || $type === 'ptt' || $type === 'voice' => MessageType::Audio,
            str_contains($type, 'video') => MessageType::Video,
            str_contains($type, 'document') || str_contains($type, 'application') || $type === 'documento' || $type === 'file' => MessageType::Document,
            str_contains($type, 'location') || $type === 'ubicacion' => MessageType::Location,
            str_contains($type, 'vcard') || str_contains($type, 'contact') => MessageType::Contacts,
            default => null,
        };

        if ($fromType !== null && ($fromType !== MessageType::Text || $mediaName === null)) {
            return $fromType;
        }

        return $mediaName === null ? MessageType::Text : $this->typeOfFile($mediaName);
    }

    public function typeOfFile(string $name): MessageType
    {
        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'heic'], true) => MessageType::Image,
            $extension === 'webp' => MessageType::Sticker,
            in_array($extension, ['opus', 'ogg', 'mp3', 'm4a', 'aac', 'amr', 'wav'], true) => MessageType::Audio,
            in_array($extension, ['mp4', '3gp', 'mov', 'avi', 'mkv'], true) => MessageType::Video,
            $extension === 'vcf' => MessageType::Contacts,
            default => MessageType::Document,
        };
    }

    /**
     * Base name of a media reference (a URL or path of the source).
     */
    public function mediaName(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $path = (string) (parse_url($value, PHP_URL_PATH) ?: $value);
        $base = SafeZip::baseName(rawurldecode($path));

        return $base === '' || $base === '.' ? null : Str::limit($base, 255, '');
    }

    /**
     * Tag names of a cell ("Ventas, VIP" / "Ventas;VIP" / "Ventas|VIP").
     *
     * @return list<string>
     */
    public function tags(?string ...$values): array
    {
        $tags = [];

        foreach ($values as $value) {
            foreach (preg_split('/[,;|]/', (string) $value) ?: [] as $tag) {
                $tag = $this->label($tag, 60);

                if ($tag !== null && ! in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)) {
                    $tags[] = $tag;
                }
            }
        }

        return array_slice($tags, 0, 30);
    }
}
