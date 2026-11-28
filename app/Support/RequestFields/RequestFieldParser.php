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
 * WhatsApp often loses the line breaks («*Cliente:*Ana *Sucursal:* Tula
 * *Monto solicitado:*3000»): a line is split before every known label
 * followed by a colon written inside it, and before a known label that
 * follows a run of spaces («$5000  Producto crédito comadres»), so each
 * label still gets its own value. The request headings people write
 * («Solicito autorización para crédito nuevo», «Solicito validación de
 * negocio para crédito grupal», «SOLICITUD CRÉDITO REACTIVACIÓN») are read
 * too: their type becomes «Tipo», and parseRequest() tells whether one was
 * written and whether it is a group credit.
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

    /** Words that make a «Solicito …» sentence a request heading. */
    private const REQUEST_HEADING_WORDS = ['solicitud', 'credito', 'autorizacion', 'validacion', 'grupal', 'grupo'];

    /** Words of a request heading at most («Solicito validación de negocio para crédito grupal»). */
    private const MAX_HEADING_WORDS = 12;

    /** Words a known label takes at most («nombre del cliente»). */
    private const MAX_KNOWN_LABEL_WORDS = 4;

    /**
     * @param  string|null  $context  RequestFieldCatalog::REQUEST or RESOLUTION; null decides by the text (an announced result reads as a resolution).
     * @return list<array{label: string, key: string, value: string, known: bool}>
     */
    public function parse(?string $text, ?string $context = null): array
    {
        return $this->parseRequest($text, $context)['rows'];
    }

    /**
     * The rows of parse(), with whether a request heading was written
     * («Solicitud de crédito», «Solicito autorización para crédito nuevo»)
     * and whether a heading names a group credit («… crédito grupal»).
     *
     * @param  string|null  $context  As in parse().
     * @return array{rows: list<array{label: string, key: string, value: string, known: bool}>, heading: bool, group: bool}
     */
    public function parseRequest(?string $text, ?string $context = null): array
    {
        if ($text === null || trim($text) === '') {
            return ['rows' => [], 'heading' => false, 'group' => false];
        }

        $context ??= RequestOutcomeEvidence::announced($text) === null ? RequestFieldCatalog::REQUEST : RequestFieldCatalog::RESOLUTION;
        $lines = array_slice(preg_split('/\R/u', $text) ?: [], 0, self::MAX_LINES);
        $rows = [];
        $hasType = false;
        $hasHeading = false;
        $group = false;

        foreach ($lines as $line) {
            foreach ($this->segments($line, $context) as $segment) {
                $clean = RequestFieldCatalog::stripDecorations($segment);

                if ($clean === '') {
                    continue;
                }

                $normalized = RequestFieldCatalog::normalize($clean);

                // «Solicitud de crédito» heading, optionally with its type on the same line.
                $heading = self::headingOf($normalized);

                if ($heading !== null) {
                    $hasHeading = true;
                    $group = $group || $heading['group'];

                    if ($heading['type'] !== null && ! $hasType) {
                        $rows[] = $this->row('tipo', $heading['type']);
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
        }

        return ['rows' => $rows, 'heading' => $hasHeading, 'group' => $group];
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
     * A request heading read from a normalized piece of a line, with the
     * type it names and whether it is a group credit, or null:
     *
     * - «Solicitud», «Solicitud de crédito», «Solicitud crédito
     *   reactivación» (typos and short forms included);
     * - a short «Solicito …» sentence about a credit, an authorization or a
     *   validation, without figures («Solicito autorización para crédito
     *   nuevo», «Solicito validación de negocio para crédito grupal»).
     *
     * @return array{type: string|null, group: bool}|null
     */
    public static function headingOf(string $normalized): ?array
    {
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^solicitud(?: (?:de )?credito)?(?: (?<type>\p{L}+))?$/u', FuzzyText::canonical($normalized, self::HEADING_WORDS, self::HEADING_ALIASES), $heading) === 1) {
            $written = $heading['type'] ?? '';

            return ['type' => self::typeOf($written), 'group' => $written === 'grupal'];
        }

        $words = FuzzyText::words($normalized);

        if (count($words) < 3 || count($words) > self::MAX_HEADING_WORDS || preg_match('/\d/', $normalized) === 1) {
            return null;
        }

        $canonical = FuzzyText::words(FuzzyText::canonical($normalized, self::REQUEST_HEADING_WORDS, self::HEADING_ALIASES));

        // «Solicito», «Solicitamos», «Solicitud»…
        if ($canonical[0] !== 'solicitud' && ! str_starts_with($words[0], 'solicit')) {
            return null;
        }

        $rest = array_slice($canonical, 1);

        if (array_intersect($rest, ['credito', 'autorizacion', 'validacion']) === []) {
            return null;
        }

        $type = null;

        foreach (array_slice($words, 1) as $word) {
            $type = strlen($word) >= 5 ? self::typeOf($word) : null;

            if ($type !== null) {
                break;
            }
        }

        return ['type' => $type, 'group' => array_intersect($rest, ['grupal', 'grupo']) !== []];
    }

    /**
     * The pieces of one line that each hold one label: the line is split
     * before a known label that follows a run of spaces («$5000  Producto
     * crédito comadres»), and before every known label followed by a colon
     * written after another label's value («Cliente:Ana Sucursal: Tula» →
     * «Cliente:Ana», «Sucursal: Tula»). The first colon of a piece only
     * splits it when what comes before the label cannot be part of it (a
     * heading, a known label with its value, or more words than a label
     * has), so «Nombre del aval: Pedro» stays whole.
     *
     * @return list<string>
     */
    private function segments(string $line, string $context): array
    {
        if (! str_contains($line, ':') && preg_match('/\S\h{2,}\S/u', $line) !== 1) {
            return [$line];
        }

        $pieces = [];

        foreach (preg_split('/\h{2,}/u', $line) ?: [] as $part) {
            if ($pieces !== [] && ! $this->startsWithKnownLabel($part, $context)) {
                $pieces[count($pieces) - 1] .= ' '.$part;
            } else {
                $pieces[] = $part;
            }
        }

        $segments = [];

        foreach ($pieces as $piece) {
            array_push($segments, ...$this->splitOnLabels($piece, $context));
        }

        return $segments;
    }

    /**
     * Whether a piece of a line starts with a known label, with or without
     * its colon.
     */
    private function startsWithKnownLabel(string $piece, string $context): bool
    {
        $clean = RequestFieldCatalog::stripDecorations($piece);
        $colon = mb_strpos($clean, ':');

        if ($colon !== false && RequestFieldCatalog::knownKey(RequestFieldCatalog::normalize(mb_substr($clean, 0, $colon)), $context) !== null) {
            return true;
        }

        return RequestFieldCatalog::leadingKey(RequestFieldCatalog::normalize($clean), $context) !== null;
    }

    /**
     * A piece of a line cut before every known label followed by a colon.
     *
     * @return list<string>
     */
    private function splitOnLabels(string $piece, string $context): array
    {
        if (preg_match_all('/:/', $piece, $colons, PREG_OFFSET_CAPTURE) < 1) {
            return [$piece];
        }

        $cuts = [];
        $from = 0;
        $segmentStart = 0;
        $first = true;

        foreach ($colons[0] as [, $position]) {
            $start = $this->trailingLabelStart(substr($piece, $from, $position - $from), $context);

            if ($start !== null) {
                $cut = $from + $start;
                $lead = substr($piece, $segmentStart, $cut - $segmentStart);

                if (RequestFieldCatalog::stripDecorations($lead) !== ''
                    && (! $first || $this->mayPrecedeLabel($lead, substr($piece, $segmentStart, $position - $segmentStart), $context))) {
                    $cuts[] = $cut;
                    $segmentStart = $cut;
                }
            }

            $from = $position + 1;
            $first = false;
        }

        $segments = [];
        $offset = 0;

        foreach ($cuts as $cut) {
            $segments[] = substr($piece, $offset, $cut - $offset);
            $offset = $cut;
        }

        $segments[] = substr($piece, $offset);

        return $segments;
    }

    /**
     * Byte offset in $before where the known label written right before a
     * colon starts (the longest reading), or null.
     */
    private function trailingLabelStart(string $before, string $context): ?int
    {
        if (preg_match_all('/\S+/u', $before, $tokens, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $tokens = $tokens[0];

        for ($count = min(self::MAX_KNOWN_LABEL_WORDS, count($tokens)); $count >= 1; $count--) {
            $window = array_slice($tokens, -$count);
            $label = RequestFieldCatalog::normalize(implode(' ', array_column($window, 0)));

            if ($label !== '' && RequestFieldCatalog::knownKey($label, $context) !== null) {
                return $window[0][1];
            }
        }

        return null;
    }

    /**
     * Whether the text before the first label of a piece is not part of
     * that label: a heading, an announced result («*Se autoriza crédito
     * nuevo* Producto: S12»), a known label with its value, or more words
     * than a label may have.
     */
    private function mayPrecedeLabel(string $lead, string $before, string $context): bool
    {
        $whole = RequestFieldCatalog::normalize($before);

        if (RequestFieldCatalog::knownKey($whole, $context) !== null) {
            return false;
        }

        $normalizedLead = RequestFieldCatalog::normalize($lead);

        return count(FuzzyText::words($whole)) > self::MAX_LABEL_WORDS
            || self::headingOf($normalizedLead) !== null
            || RequestOutcomeEvidence::announced($lead) !== null
            || RequestFieldCatalog::leadingKey($normalizedLead, $context) !== null;
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
