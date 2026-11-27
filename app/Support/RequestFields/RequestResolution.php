<?php

namespace App\Support\RequestFields;

use App\Enums\ConversationOutcome;
use App\Support\RequestOutcomeEvidence;

/**
 * The resolution data of the authorization (or rejection) the team sends
 * the customer, read into request fields:
 *
 *     *Se autoriza reactivación de crédito*
 *     Producto: S12 JUEVES
 *     Cliente: BEATRIZ GUERRERO OLVERA
 *     Monto autorizado: $3000
 *     Desembolso: $2,130
 *     Cantidad a pagar: $398
 *
 * Only a text whose opening announces a result counts
 * (RequestOutcomeEvidence::announced()); its lines are read in the
 * RESOLUTION context, typos included («Desenbolso», «Monto aut», a bare
 * «Monto»). The amounts are kept only when they read as money, written the
 * same way everywhere («$3,000»); what the team sends last decides them.
 * The client, the product and the type (read from the opening, «… de
 * reactivación …») only complete a request that lacks them: the data the
 * field manager sent stays.
 *
 * Pure: no database, no container.
 */
final class RequestResolution
{
    /** Amounts of the resolution, kept as money. */
    public const MONEY = ['monto_autorizado', 'desembolso', 'cantidad_a_pagar'];

    /** Kept as the latest written: the resolution decides them. */
    public const LATEST = [...self::MONEY, 'plazo'];

    /** Only completed when the request does not have them yet. */
    public const IF_MISSING = ['cliente', 'producto', 'tipo'];

    /** Lines of the opening where the type is looked for. */
    private const OPENING_LINES = 2;

    /**
     * @return array{outcome: ConversationOutcome, latest: array<string, string>, if_missing: array<string, string>}|null
     */
    public static function read(?string $text): ?array
    {
        $outcome = RequestOutcomeEvidence::announced($text);

        if ($outcome === null || $text === null) {
            return null;
        }

        $latest = [];
        $ifMissing = [];

        foreach ((new RequestFieldParser)->parse($text, RequestFieldCatalog::RESOLUTION) as $row) {
            $key = $row['key'];
            $value = $row['value'];

            if ($value === '') {
                continue;
            }

            if (in_array($key, self::MONEY, true)) {
                $amount = RequestOutcomeEvidence::amount($value);

                if ($amount !== null) {
                    $latest[$key] ??= RequestOutcomeEvidence::display($amount);
                }
            } elseif (in_array($key, self::LATEST, true)) {
                $latest[$key] ??= $value;
            } elseif (in_array($key, self::IF_MISSING, true)) {
                $ifMissing[$key] ??= $value;
            }
        }

        $type = self::announcedType($text);

        if ($type !== null) {
            $ifMissing['tipo'] ??= $type;
        }

        return ['outcome' => $outcome, 'latest' => $latest, 'if_missing' => $ifMissing];
    }

    /**
     * Whether two values of a field are the same: amounts compare as money
     * («$3000» is «$3,000.00»), anything else as written.
     */
    public static function sameValue(string $key, string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (! in_array($key, self::MONEY, true)) {
            return false;
        }

        $amount = RequestOutcomeEvidence::amount($a);

        return $amount !== null && $amount === RequestOutcomeEvidence::amount($b);
    }

    /**
     * The type named by the opening («Se autoriza reactivación de
     * crédito» → «Reactivación»), or null.
     */
    private static function announcedType(string $text): ?string
    {
        $lines = array_filter(array_map(
            fn (string $line): string => RequestFieldCatalog::normalize($line),
            preg_split('/\R/u', $text) ?: [],
        ), fn (string $line): bool => $line !== '');

        foreach (array_slice(array_values($lines), 0, self::OPENING_LINES) as $line) {
            foreach (FuzzyText::words($line) as $word) {
                $type = strlen($word) >= 5 ? RequestFieldParser::typeOf($word) : null;

                if ($type !== null) {
                    return $type;
                }
            }
        }

        return null;
    }
}
