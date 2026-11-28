<?php

namespace App\Support;

use App\Enums\ConversationOutcome;
use App\Support\RequestFields\FuzzyText;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestFieldParser;

/**
 * Reads the result of a credit request out of the text the team sent the
 * customer, to pre-select it when the request is completed. The quick
 * replies of the team announce it on their first line («*Se autoriza
 * reactivación de crédito*», «Solicitud rechazada») and carry the amount on
 * a «Monto autorizado: $3,000» line. It only suggests: the person confirms
 * or changes it, and nothing is ever recorded from the text alone.
 *
 * Read tolerantly, as people type in a hurry: the opening words may have
 * typos or be cut short («Se autorisa reactibacion de cred», «Solicitud
 * rechasada», FuzzyText) and the amount line is any label that reads as
 * «Monto autorizado» in an authorization, a bare «Monto:» included
 * (RequestFieldParser in the RESOLUTION context).
 */
final class RequestOutcomeEvidence
{
    /**
     * Largest amount the column holds (decimal 12,2).
     */
    public const MAX_AMOUNT = 9999999999.99;

    /**
     * Openings (normalized: lower case, no accents nor formatting) that
     * announce an approved credit.
     *
     * @var list<string>
     */
    private const APPROVED = [
        'se autoriza',
        'se aprueba',
        'credito autorizado',
        'credito aprobado',
        'solicitud autorizada',
        'solicitud aprobada',
    ];

    /**
     * Openings that announce a rejected request. Checked first: «no se
     * autoriza» must never read as an approval.
     *
     * @var list<string>
     */
    private const REJECTED = [
        'solicitud rechazada',
        'solicitud rechazado',
        'se rechaza',
        'rechazada',
        'rechazado',
        'credito rechazado',
        'credito no autorizado',
        'credito no aprobado',
        'solicitud no autorizada',
        'solicitud no aprobada',
        'no se autoriza',
        'no se aprueba',
        'no autorizado',
        'no autorizada',
        'no aprobado',
        'no aprobada',
    ];

    /**
     * Words of the openings, read tolerantly (FuzzyText::canonical()).
     *
     * @var list<string>
     */
    private const VOCABULARY = [
        'autoriza', 'autorizado', 'autorizada', 'aprueba', 'aprobado', 'aprobada',
        'rechaza', 'rechazada', 'rechazado', 'credito', 'solicitud',
    ];

    /**
     * Short or mistaken forms that the distance alone does not settle.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'autorizo' => 'autoriza',
        'autoriso' => 'autoriza',
        'aprobo' => 'aprueba',
        'rechazo' => 'rechaza',
        'cred' => 'credito',
        'credit' => 'credito',
    ];

    /**
     * Lines read from the top of the text: the announcement may follow a
     * short greeting.
     */
    private const LINES = 2;

    /**
     * The result announced by the text and the authorized amount it names
     * (approved only), or null when the text announces none.
     *
     * @return array{outcome: ConversationOutcome, amount: string|null}|null
     */
    public static function fromText(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $outcome = self::announced($text);

        if ($outcome === null) {
            return null;
        }

        return [
            'outcome' => $outcome,
            'amount' => $outcome === ConversationOutcome::Approved ? self::authorizedAmount($text) : null,
        ];
    }

    /**
     * The result the opening of the text announces, or null. Blank lines
     * and a quote pasted on top («> …») do not count as the opening.
     */
    public static function announced(?string $text): ?ConversationOutcome
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $lines = array_slice(array_values(array_filter(
            array_map(
                // A future or pending decision («Se autorizará…», «por autorizar»,
                // «en revisión») announces nothing yet, however close it reads.
                fn (string $line): string => self::undecided($line) ? '' : self::normalize($line),
                self::withoutLeadingQuote(preg_split('/\R/u', $text) ?: []),
            ),
            fn (string $line): bool => $line !== '',
        )), 0, self::LINES);

        foreach ($lines as $line) {
            if (self::startsWithAny($line, self::REJECTED)) {
                return ConversationOutcome::Rejected;
            }

            if (self::startsWithAny($line, self::APPROVED)) {
                return ConversationOutcome::Approved;
            }
        }

        return null;
    }

    /**
     * The amount of the «Monto autorizado: …» line of the text (or of any
     * label that reads as it in an authorization: «Monto aut», «Monto»), as
     * a decimal string with two places («3000.00»), or null.
     */
    public static function authorizedAmount(string $text): ?string
    {
        foreach ((new RequestFieldParser)->parse($text, RequestFieldCatalog::RESOLUTION) as $row) {
            if ($row['key'] === 'monto_autorizado') {
                $amount = self::amount($row['value']);

                if ($amount !== null) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * A money amount written by a person («$3,000», «3000», «$ 3,000.00»,
     * «3 000», «3.000», «3000,50») as a decimal string with two places, or
     * null when there is none or it does not fit the column.
     */
    public static function amount(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // «3 mil», «2.5 mil».
        if (preg_match('/(\d{1,7})(?:[.,](\d{1,3}))?\s*mil\b/iu', $text, $thousands) === 1) {
            $number = (float) ($thousands[1].'.'.($thousands[2] ?? '0')) * 1000;

            return $number > self::MAX_AMOUNT ? null : number_format($number, 2, '.', '');
        }

        if (preg_match('/\d[\d,. ]*\d|\d/u', $text, $match) !== 1) {
            return null;
        }

        $raw = str_replace(' ', '', $match[0]);

        $number = match (true) {
            // 3,000 / 3,000.50 (Mexico).
            preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $raw) === 1 => str_replace(',', '', $raw),
            // 3.000 / 3.000,50.
            preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $raw) === 1 => str_replace(['.', ','], ['', '.'], $raw),
            // 3000 / 3000.50.
            preg_match('/^\d+(\.\d{1,2})?$/', $raw) === 1 => $raw,
            // 3000,50.
            preg_match('/^\d+,\d{1,2}$/', $raw) === 1 => str_replace(',', '.', $raw),
            default => null,
        };

        if ($number === null || (float) $number > self::MAX_AMOUNT) {
            return null;
        }

        return number_format((float) $number, 2, '.', '');
    }

    /**
     * A decimal amount («3000.00») as people read it: «$3,000», «$3,000.50».
     */
    public static function display(string $decimal): string
    {
        $value = (float) $decimal;

        return '$'.number_format($value, floor($value) === $value ? 0 : 2, '.', ',');
    }

    /**
     * Lower case, without accents, WhatsApp formatting, emojis nor
     * punctuation, with single spaces, and its words read as those of the
     * openings when they are close enough («autorisa» → «autoriza»).
     */
    private static function normalize(string $line): string
    {
        return FuzzyText::canonical(RequestFieldCatalog::normalize($line), self::VOCABULARY, self::ALIASES);
    }

    /**
     * The lines without the quote that opens them («> Cliente: Ana …», the
     * message being answered pasted on top), when the answer follows it.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function withoutLeadingQuote(array $lines): array
    {
        $index = 0;

        while ($index < count($lines) && (trim($lines[$index]) === '' || str_starts_with(ltrim($lines[$index]), '>'))) {
            $index++;
        }

        $rest = array_slice($lines, $index);

        return $index > 0 && implode('', array_map('trim', $rest)) !== '' ? $rest : $lines;
    }

    /**
     * Whether the line speaks of a decision still to come: a future tense
     * («autorizará», «aprobará», «rechazará»), «va a autorizar», «por
     * autorizar», «pendiente» or «en revisión».
     */
    private static function undecided(string $line): bool
    {
        return preg_match('/\b(autori[sz]ara|aprobara|rechazara|va a (autori[sz]ar|aprobar|rechazar)|por (autori[sz]ar|aprobar)|pendiente|en revision)\b/u', RequestFieldCatalog::normalize($line)) === 1;
    }

    /**
     * @param  list<string>  $openings
     */
    private static function startsWithAny(string $line, array $openings): bool
    {
        foreach ($openings as $opening) {
            if (str_starts_with($line, $opening)) {
                return true;
            }
        }

        return false;
    }
}
