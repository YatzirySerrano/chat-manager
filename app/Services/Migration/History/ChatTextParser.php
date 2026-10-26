<?php

namespace App\Services\Migration\History;

use App\Enums\MessageType;
use Carbon\CarbonImmutable;

/**
 * Tolerant parser of a WhatsApp / Whaticket "Chat.txt".
 *
 * NO VERIFICADO CON EXPORTACIÓN AUTÉNTICA: WhatsApp does not publish the
 * line format and Whaticket does not document its Chat.txt, so several
 * variants are accepted (general knowledge, to be checked against real
 * test exports):
 * - Android: "25/10/26, 14:05 - Nombre: texto" (also "2:05 p. m." / "PM",
 *   with or without the comma, "-" or "–");
 * - iOS: "[25/10/26, 14:05:33] Nombre: texto" (invisible direction marks
 *   are ignored);
 * - day-first or month-first dates (decided from the whole file: a first
 *   number above 12 means day-first, a second one above 12 month-first;
 *   day-first by default), two- or four-digit years, "/", "." or "-";
 * - lines without a header continue the previous message (multi-line);
 * - lines with a header but without "Name: " are system lines (encryption
 *   notice, someone joined...), kept apart and never imported as messages;
 * - attachments: "<adjunto: X>", "<attached: X>", "X (archivo adjunto)",
 *   "X (file attached)"; omitted media ("<Multimedia omitido>",
 *   "<Media omitted>", "imagen omitida", "image omitted"...) becomes a
 *   message whose file is missing.
 *
 * @phpstan-type ChatLine array{line: int, occurred_at: CarbonImmutable|null, sender: string|null, body: string|null, media_name: string|null, type: MessageType, is_system: bool}
 */
class ChatTextParser
{
    private const HEADER_IOS = '/^\[(\d{1,4})[\/.\-](\d{1,2})[\/.\-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?(\s*[ap]\.?\s*m\.?)?\]\s?(.*)$/iu';

    private const HEADER_ANDROID = '/^(\d{1,4})[\/.\-](\d{1,2})[\/.\-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?(\s*[ap]\.?\s*m\.?)?\s+[\-–]\s(.*)$/iu';

    /**
     * Words of omitted media and the type they mean.
     *
     * @var array<string, MessageType>
     */
    private const OMITTED = [
        'multimedia omitido' => MessageType::Image,
        'media omitted' => MessageType::Image,
        'midia oculta' => MessageType::Image,
        'imagen omitida' => MessageType::Image,
        'image omitted' => MessageType::Image,
        'audio omitido' => MessageType::Audio,
        'audio omitted' => MessageType::Audio,
        'video omitido' => MessageType::Video,
        'video omitted' => MessageType::Video,
        'sticker omitido' => MessageType::Sticker,
        'sticker omitted' => MessageType::Sticker,
        'documento omitido' => MessageType::Document,
        'document omitted' => MessageType::Document,
        'gif omitido' => MessageType::Video,
        'gif omitted' => MessageType::Video,
    ];

    public function __construct(private HistoryValues $values) {}

    /**
     * @return array{format: string, lines: list<ChatLine>, participants: list<string>, unparsed: int}
     */
    public function parse(string $content, string $timezone): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $rawLines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $headers = [];
        $variant = null;

        foreach ($rawLines as $number => $raw) {
            $line = $this->clean($raw);

            if (preg_match(self::HEADER_IOS, $line, $matches) === 1) {
                $headers[$number] = $matches;
                $variant ??= 'ios';
            } elseif (preg_match(self::HEADER_ANDROID, $line, $matches) === 1) {
                $headers[$number] = $matches;
                $variant ??= 'android';
            }
        }

        $order = $this->dateOrder($headers);
        $twelveHour = false;
        $lines = [];
        $current = null;
        $unparsed = 0;

        foreach ($rawLines as $number => $raw) {
            if (! isset($headers[$number])) {
                $text = $this->clean($raw);

                if ($current !== null) {
                    $current['body'] = $current['body']."\n".$text;
                } elseif (trim($text) !== '') {
                    $unparsed++;
                }

                continue;
            }

            if ($current !== null) {
                $lines[] = $this->finish($current);
            }

            $matches = $headers[$number];
            $twelveHour = $twelveHour || trim($matches[7]) !== '';
            $rest = (string) $matches[8];
            $separator = mb_strpos($rest, ': ');
            $isSystem = $separator === false;

            $current = [
                'line' => $number + 1,
                'occurred_at' => $this->dateOf($matches, $order, $timezone),
                'sender' => $isSystem ? null : $this->values->label(mb_substr($rest, 0, $separator)),
                'body' => $isSystem ? $rest : mb_substr($rest, $separator + 2),
                'is_system' => $isSystem,
            ];
        }

        if ($current !== null) {
            $lines[] = $this->finish($current);
        }

        $participants = [];

        foreach ($lines as $line) {
            if (! $line['is_system'] && $line['sender'] !== null && ! in_array($line['sender'], $participants, true)) {
                $participants[] = $line['sender'];
            }
        }

        $format = 'chat-txt:'.($variant ?? 'desconocido').':'.$order.':'.($twelveHour ? '12h' : '24h');

        return ['format' => $format, 'lines' => $lines, 'participants' => $participants, 'unparsed' => $unparsed];
    }

    /**
     * Name of the other person in a WhatsApp export file name
     * ("Chat de WhatsApp con Ana.txt", "WhatsApp Chat with Ana.zip").
     */
    public function nameFromFileName(string $name): ?string
    {
        if (preg_match('/(?:chat de whatsapp con|whatsapp chat with|conversa do whatsapp com)\s+(.+?)(?:\.(?:txt|zip))?$/iu', trim($name), $matches) === 1) {
            return $this->values->label($matches[1]);
        }

        return null;
    }

    /**
     * Whether a ZIP entry is a chat transcript.
     */
    public static function isChatText(string $baseName): bool
    {
        return str_ends_with($baseName, '.txt')
            && (in_array($baseName, ['chat.txt', '_chat.txt'], true) || str_contains($baseName, 'chat'));
    }

    /**
     * @param  array{line: int, occurred_at: CarbonImmutable|null, sender: string|null, body: string|null, is_system: bool}  $line
     * @return ChatLine
     */
    private function finish(array $line): array
    {
        $body = trim((string) $line['body']);
        $media = null;
        $type = MessageType::Text;

        if (! $line['is_system']) {
            if (preg_match('/^<(?:adjunto|attached|anexado):\s*([^>]+)>\s*(.*)$/isu', $body, $matches) === 1) {
                $media = $this->values->mediaName($matches[1]);
                $body = trim($matches[2]);
            } elseif (preg_match('/^(\S[^\n]*?)\s+\((?:archivo adjunto|file attached|arquivo anexado)\)\s*(.*)$/isu', $body, $matches) === 1) {
                $media = $this->values->mediaName($matches[1]);
                $body = trim($matches[2]);
            }

            if ($media !== null) {
                $type = $this->values->typeOfFile($media);
            } else {
                $omitted = strtr(mb_strtolower(trim($body, ' <>')), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

                if (isset(self::OMITTED[$omitted])) {
                    $type = self::OMITTED[$omitted];
                    $body = '';
                }
            }
        }

        return [
            'line' => $line['line'],
            'occurred_at' => $line['occurred_at'],
            'sender' => $line['sender'],
            'body' => $body === '' ? null : $body,
            'media_name' => $media,
            'type' => $type,
            'is_system' => $line['is_system'],
        ];
    }

    /**
     * Invisible direction and formatting marks removed, narrow spaces
     * written as spaces.
     */
    private function clean(string $line): string
    {
        $line = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $line);

        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $line);
    }

    /**
     * "ymd" for four-digit first numbers, "mdy" when a second number is
     * above 12, otherwise "dmy".
     *
     * @param  array<int, array<int, string>>  $headers
     */
    private function dateOrder(array $headers): string
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($headers as $matches) {
            if (strlen($matches[1]) === 4) {
                return 'ymd';
            }

            $dayFirst = $dayFirst || (int) $matches[1] > 12;
            $monthFirst = $monthFirst || (int) $matches[2] > 12;
        }

        return $monthFirst && ! $dayFirst ? 'mdy' : 'dmy';
    }

    /**
     * @param  array<int, string>  $matches
     */
    private function dateOf(array $matches, string $order, string $timezone): ?CarbonImmutable
    {
        [$first, $second, $third] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];

        [$year, $month, $day] = match ($order) {
            'ymd' => [$first, $second, $third],
            'mdy' => [$third, $first, $second],
            default => [$third, $second, $first],
        };

        if ($year < 100) {
            $year += 2000;
        }

        $hour = (int) $matches[4];
        $meridiem = strtolower(trim((string) preg_replace('/[^ap]/i', '', $matches[7] ?? '')));

        if ($meridiem !== '') {
            $hour = $hour % 12 + ($meridiem === 'p' ? 12 : 0);
        }

        if (! checkdate($month, $day, $year) || $hour > 23 || (int) $matches[5] > 59) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, $hour, (int) $matches[5], (int) ($matches[6] ?? 0), $timezone)?->utc();
    }
}
