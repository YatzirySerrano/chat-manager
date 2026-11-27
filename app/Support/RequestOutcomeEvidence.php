<?php

namespace App\Support;

use App\Enums\ConversationOutcome;

/**
 * Reads the result of a credit request out of the text the team sent the
 * customer, to pre-select it when the request is completed. The quick
 * replies of the team announce it on their first line («*Se autoriza
 * reactivación de crédito*», «Solicitud rechazada») and carry the amount on
 * a «Monto autorizado: $3,000» line. It only suggests: the person confirms
 * or changes it, and nothing is ever recorded from the text alone.
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

        $lines = array_slice(array_values(array_filter(
            array_map(fn (string $line): string => self::normalize($line), preg_split('/\R/u', $text) ?: []),
            fn (string $line): bool => $line !== '',
        )), 0, self::LINES);

        foreach ($lines as $line) {
            if (self::startsWithAny($line, self::REJECTED)) {
                return ['outcome' => ConversationOutcome::Rejected, 'amount' => null];
            }

            if (self::startsWithAny($line, self::APPROVED)) {
                return ['outcome' => ConversationOutcome::Approved, 'amount' => self::authorizedAmount($text)];
            }
        }

        return null;
    }

    /**
     * The amount of the «Monto autorizado: …» line of the text, as a decimal
     * string with two places («3000.00»), or null.
     */
    public static function authorizedAmount(string $text): ?string
    {
        $plain = str_replace(['*', '_', '~'], '', $text);

        if (preg_match('/monto\s+autorizado\s*[:=\-]?\s*([^\r\n]+)/iu', $plain, $match) !== 1) {
            return null;
        }

        return self::amount($match[1]);
    }

    /**
     * A money amount written by a person («$3,000», «3000», «$ 3,000.00»,
     * «3 000», «3.000», «3000,50») as a decimal string with two places, or
     * null when there is none or it does not fit the column.
     */
    public static function amount(?string $text): ?string
    {
        if ($text === null || preg_match('/\d[\d,. ]*\d|\d/u', $text, $match) !== 1) {
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
     * Lower case, without accents, WhatsApp formatting (*, _, ~) nor
     * leading symbols or emoji, with single spaces.
     */
    private static function normalize(string $line): string
    {
        $line = mb_strtolower(str_replace(['*', '_', '~'], '', $line));
        $line = strtr($line, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        $line = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $line);

        return trim((string) preg_replace('/\s+/u', ' ', $line));
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
