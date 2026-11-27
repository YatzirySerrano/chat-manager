<?php

namespace App\Support\RequestFields;

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
 * labeled line at all (a sentence, a time «10:30», a link).
 *
 * Pure: no database, no container.
 */
final class RequestFieldParser
{
    /** Lines read from one message at most. */
    private const MAX_LINES = 60;

    /** Words a label without a known name may have («Nombre del aval»). */
    private const MAX_LABEL_WORDS = 5;

    /**
     * @return list<array{label: string, key: string, value: string, known: bool}>
     */
    public function parse(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $lines = array_slice(preg_split('/\R/u', $text) ?: [], 0, self::MAX_LINES);
        $aliases = RequestFieldCatalog::aliases();
        $rows = [];
        $hasType = false;

        foreach ($lines as $line) {
            $clean = RequestFieldCatalog::stripDecorations($line);

            if ($clean === '') {
                continue;
            }

            $normalized = RequestFieldCatalog::normalize($clean);

            // «Solicitud de crédito» heading, optionally with its type on the same line.
            if (preg_match('/^solicitud(?: de credito)?(?: (?<type>\p{L}+))?$/u', $normalized, $heading) === 1) {
                $type = $heading['type'] ?? '';

                if (isset(RequestFieldCatalog::TYPES[$type]) && ! $hasType) {
                    $rows[] = $this->row('tipo', RequestFieldCatalog::TYPES[$type]);
                    $hasType = true;
                }

                continue;
            }

            // The type alone on its line (right under the heading, usually).
            if (isset(RequestFieldCatalog::TYPES[$normalized])) {
                if (! $hasType) {
                    $rows[] = $this->row('tipo', RequestFieldCatalog::TYPES[$normalized]);
                    $hasType = true;
                }

                continue;
            }

            $row = $this->labeled($clean, $aliases);

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
     * One «Etiqueta: valor» line (or a known label followed by its value).
     *
     * @param  list<array{0: string, 1: string}>  $aliases
     * @return array{label: string, key: string, value: string, known: bool}|null
     */
    private function labeled(string $clean, array $aliases): ?array
    {
        $colon = mb_strpos($clean, ':');

        if ($colon !== false) {
            $label = trim(mb_substr($clean, 0, $colon));
            $value = trim(mb_substr($clean, $colon + 1));
            $normalizedLabel = RequestFieldCatalog::normalize($label);
            $known = RequestFieldCatalog::knownKey($normalizedLabel);

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
        $normalized = RequestFieldCatalog::normalize($clean);

        foreach ($aliases as [$alias, $key]) {
            if (! str_starts_with($normalized.' ', $alias.' ')) {
                continue;
            }

            $value = $this->afterWords($clean, substr_count($alias, ' ') + 1);

            return $value === null ? null : $this->row($key, (string) preg_replace('/^[\s\-–—=:.]+/u', '', $value));
        }

        return null;
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
