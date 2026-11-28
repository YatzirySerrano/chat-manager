<?php

namespace App\Support\RequestFields;

use App\Support\RequestOutcomeEvidence;

/**
 * A credit request a field manager writes in the chat, read into request
 * fields so «Datos de la solicitud» fills itself on arrival:
 *
 *     *Solicito autorización para crédito nuevo:* *Cliente:*Ana Leticia
 *     *Sucursal:* Atlacomulco *Monto solicitado:*3000 *Producto:* s12
 *
 * A text counts as a request when it names a client («Cliente», «Nombre»,
 * typos included) and either one more known field of a request
 * (QUALIFYING: sucursal, amount, product, phone, business...) or a request
 * heading («Solicitud de crédito», «Solicito validación de negocio para
 * crédito grupal»). Only known fields are kept, never the amounts of a
 * resolution (those come from the team, RequestResolution), and a text that
 * announces a result is not a request. A group credit is told by its
 * heading («… grupal») or by a «Nombre del grupo».
 *
 * Read with RequestFieldParser in the REQUEST context, so labels written on
 * one line, WhatsApp markers and typos are understood.
 *
 * Pure: no database, no container.
 */
final class RequestSubmission
{
    /** Fields that, with the client, make a text a request. */
    public const QUALIFYING = ['sucursal', 'monto_solicitado', 'monto_anterior', 'producto', 'telefono', 'giro_de_negocio', 'grupo', 'ciclo', 'dia_de_pago'];

    /** Longest text read (characters): a request is a short message. */
    public const MAX_LENGTH = 4000;

    /** Longest client name accepted (characters). */
    private const CLIENT_MAX = 120;

    /**
     * The known fields of the request (key => value, first value of each
     * key) and whether it is a group credit, or null when the text is not
     * a request.
     *
     * @return array{fields: array<string, string>, group: bool}|null
     */
    public static function read(?string $text): ?array
    {
        if ($text === null || trim($text) === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        if (RequestOutcomeEvidence::announced($text) !== null) {
            return null;
        }

        $parsed = (new RequestFieldParser)->parseRequest($text, RequestFieldCatalog::REQUEST);
        $fields = [];

        foreach ($parsed['rows'] as $row) {
            if (! $row['known'] || $row['value'] === '' || in_array($row['key'], RequestResolution::MONEY, true)) {
                continue;
            }

            $fields[$row['key']] ??= $row['value'];
        }

        $client = $fields['cliente'] ?? null;

        if ($client === null || mb_strlen($client) > self::CLIENT_MAX || preg_match('/\p{L}{2}/u', $client) !== 1) {
            return null;
        }

        if (! $parsed['heading'] && array_intersect(array_keys($fields), self::QUALIFYING) === []) {
            return null;
        }

        return ['fields' => $fields, 'group' => $parsed['group'] || isset($fields['grupo'])];
    }
}
