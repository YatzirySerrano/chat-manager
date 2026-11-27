<?php

namespace App\Support\RequestFields;

use App\Support\RequestOutcomeEvidence;

/**
 * Reads the «Etiqueta: valor» lines of a credit request written in the chat
 * by a field manager, e.g.
 *
 *     Solicitud de crédito
 *     Nuevo
 *     Cliente: Beatriz Guerrero Olvera
 *     Monto solicitado: $5,000
 *     Giro de negocio Tortillas
 *
 * It is tolerant on purpose: WhatsApp markers, emojis and bullets are
 * ignored, a known label may come without the colon («Giro de negocio
 * Tortillas»), and the type written under the «Solicitud de crédito»
 * heading (Nuevo, Reactivación...) becomes the «Tipo» field. It only
 * suggests: every row is reviewed by the analyst, who picks what to keep,
 * so a line is never dropped for being unusual, only for not being a
 * labeled line at all (a sentence, a time «10:30», a link). Labels, the
 * heading and the type are read with typos and truncations too
 * («Solisitud de cred», «Reactibacion», «Desenbolso», FuzzyText).
 *
 * The same reading serves the authorization the team sends back («Se
 * autoriza reactivación de crédito», «Monto autorizado: $3000»): such a
 * text is read in the RESOLUTION context, where a bare «Monto» is the
 * amount authorized (RequestFieldCatalog).
 *
 * Pure: no database, no container.
 */
final class RequestFieldParser
{
    /** Lines read from one message at most. */
    private const MAX_LINES = 60;

    /** Words a label without a known name may have («Nombre del aval»). */
    private const MAX_LABEL_WORDS = 5;

    /** Words of the «Solicitud de crédito» heading, read tolerantly. */
    private const HEADING_WORDS = ['solicitud', 'de', 'credito'];

    /** Short forms people write for the heading words. */
    private const HEADING_ALIASES = ['cred' => 'credito', 'credit' => 'credito', 'sol' => 'solicitud'];

    /**
     * @param  string|null  $context  RequestFieldCatalog::REQUEST or RESOLUTION; null decides by the text (an announced result reads as a resolution).
     * @return list<array{label: string, key: string, value: string, known: bool}>
     */
    public function parse(?string $text, ?string $context = null): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $context ??= RequestOutcomeEvidence::announced($text) === null ? RequestFieldCatalog::REQUEST : RequestFieldCatalog::RESOLUTION;
        $lines = array_slice(preg_split('/\R/u', $text) ?: [], 0, self::MAX_LINES);
        $rows = [];
        $hasType = false;

        foreach ($lines as $line) {
            $clean = RequestFieldCatalog::stripDecorations($line);

            if ($clean === '') {
                continue;
            }

            $normalized = RequestFieldCatalog::normalize($clean);

            // «Solicitud de crédito» heading, optionally with its type on the same line.
            if (preg_match('/^solicitud(?: de credito)?(?: (?<type>\p{L}+))?$/u', FuzzyText::canonical($normalized, self::HEADING_WORDS, self::HEADING_ALIASES), $heading) === 1) {
                $type = self::typeOf($heading['type'] ?? '');

                if ($type !== null && ! $hasType) {
                    $rows[] = $this->row('tipo', $type);
                    $hasType = true;
                }

                continue;
            }

            // The type alone on its line (right under the heading, usually).
            $type = self::typeOf($normalized);

            if ($type !== null) {
                if (! $hasType) {
                    $rows[] = $this->row('tipo', $type);
                    $hasType = true;
                }

                continue;
            }

            $row = $this->labeled($clean, $context);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Whether the text reads as a request: at least two labeled lines with a
     * value (what makes «Tomar datos de la solicitud» prominent).
     */
    public function looksLikeRequest(?string $text): bool
    {
        $filled = array_filter($this->parse($text), fn (array $row): bool => $row['value'] !== '');

        return count($filled) >= 2;
    }

    /**
     * The request type a normalized word reads as («reactibacion» →
     * «Reactivación»), or null.
     */
    public static function typeOf(string $normalized): ?string
    {
        if ($normalized === '' || str_contains($normalized, ' ')) {
            return null;
        }

        $type = FuzzyText::closest($normalized, array_keys(RequestFieldCatalog::TYPES));

        return $type === null ? null : RequestFieldCatalog::TYPES[$type];
    }

    /**
     * One «Etiqueta: valor» line (or a known label followed by its value).
     *
     * @return array{label: string, key: string, value: string, known: bool}|null
     */
    private function labeled(string $clean, string $context): ?array
    {
        $colon = mb_strpos($clean, ':');

        if ($colon !== false) {
            $label = trim(mb_substr($clean, 0, $colon));
            $value = trim(mb_substr($clean, $colon + 1));
            $normalizedLabel = RequestFieldCatalog::normalize($label);
            $known = RequestFieldCatalog::knownKey($normalizedLabel, $context);

            if ($known !== null) {
                return $this->row($known, $value);
            }

            if ($this->isCustomLabel($label, $normalizedLabel, $value)) {
                return [
                    'label' => mb_substr($label, 0, RequestFieldCatalog::LABEL_MAX),
                    'key' => RequestFieldCatalog::slug($normalizedLabel),
                    'value' => $this->cleanValue($value),
                    'known' => false,
                ];
            }
        }

        // A known label without the colon: «Giro de negocio Tortillas».
        $leading = RequestFieldCatalog::leadingKey(RequestFieldCatalog::normalize($clean), $context);

        if ($leading === null) {
            return null;
        }

        $value = $this->afterWords($clean, $leading['words']);

        return $value === null ? null : $this->row($leading['key'], (string) preg_replace('/^[\s\-–—=:.]+/u', '', $value));
    }

    /**
     * A label nobody listed: a few words with letters, not a time or a link.
     */
    private function isCustomLabel(string $label, string $normalizedLabel, string $value): bool
    {
        return $normalizedLabel !== ''
            && preg_match('/\p{L}/u', $normalizedLabel) === 1
            && mb_strlen($label) <= RequestFieldCatalog::LABEL_MAX
            && substr_count($normalizedLabel, ' ') < self::MAX_LABEL_WORDS
            && ! str_starts_with($value, '//')
            // A time («a las 10:30») or a ratio, not a label.
            && preg_match('/\d$/u', $label) !== 1;
    }

    /**
     * The text after the first $words words of the line (as written), or
     * null when nothing follows them.
     */
    private function afterWords(string $clean, int $words): ?string
    {
        $parts = preg_split('/(?<=[\p{L}\p{N}])(?=[^\p{L}\p{N}])/u', $clean) ?: [];
        $seen = 0;
        $offset = 0;

        foreach ($parts as $part) {
            $offset += strlen($part);

            if (preg_match('/[\p{L}\p{N}]/u', $part) === 1) {
                $seen++;
            }

            if ($seen === $words) {
                $rest = trim(substr($clean, $offset));

                return $rest === '' ? null : $rest;
            }
        }

        return null;
    }

    /**
     * @return array{label: string, key: string, value: string, known: bool}
     */
    private function row(string $key, string $value): array
    {
        return [
            'label' => (string) RequestFieldCatalog::labelOf($key),
            'key' => $key,
            'value' => $this->cleanValue($value),
            'known' => true,
        ];
    }

    /**
     * The value on one line, without decorations; a lone «$» (an amount
     * left blank in the template) is no value at all.
     */
    private function cleanValue(string $value): string
    {
        $value = RequestFieldCatalog::stripDecorations($value);

        if (preg_match('/^\$?\s*$/u', $value) === 1) {
            return '';
        }

        $value = (string) preg_replace('/^\$\s+/u', '$', $value);

        return mb_substr($value, 0, RequestFieldCatalog::VALUE_MAX);
    }
}
