<?php

use App\Enums\ConversationOutcome;
use App\Support\RequestFields\RequestFieldParser;
use App\Support\RequestFields\RequestResolution;
use App\Support\RequestFields\RequestSubmission;
use App\Support\RequestOutcomeEvidence;

/*
| The credit request a field manager writes reads into request fields on
| its own: labels written on one line (WhatsApp loses the line breaks),
| headings such as «Solicito autorización para crédito nuevo», typos and
| group credits.
*/

test('labels written on one line are each read with their own value', function () {
    $text = '*Solicito autorización para crédito nuevo:* *Cliente:*Ana Leticia lezama rezendiz *Sucursal:* Atlacomulco *Monto solicitado:*3000 *Producto:* s12 *Giro:* ropa *Teléfono:* 7731349188';

    expect(RequestSubmission::read($text))->toBe([
        'fields' => [
            'tipo' => 'Nuevo',
            'cliente' => 'Ana Leticia lezama rezendiz',
            'sucursal' => 'Atlacomulco',
            'monto_solicitado' => '3000',
            'producto' => 's12',
            'giro_de_negocio' => 'ropa',
            'telefono' => '7731349188',
        ],
        'group' => false,
    ]);
});

test('a group credit request is read with its group and the phone as «Celular»', function () {
    $text = "Solicito validación de negocio     para crédito grupal cliente :MARIA ISABEL RODRIGUEZ VARGAS monto solicitado: \$5000  Producto crédito comadres Giro del negocio:VENTA POR CATALOGO\nNombre del grupo EMPRENDEDORAS MATA CLARA   Celular :  2781320471";

    expect(RequestSubmission::read($text))->toBe([
        'fields' => [
            'cliente' => 'MARIA ISABEL RODRIGUEZ VARGAS',
            'monto_solicitado' => '$5000',
            'producto' => 'crédito comadres',
            'giro_de_negocio' => 'VENTA POR CATALOGO',
            'grupo' => 'EMPRENDEDORAS MATA CLARA',
            'telefono' => '2781320471',
        ],
        'group' => true,
    ]);
});

test('a request with its heading and one field per line is read whole', function () {
    $text = "SOLICITUD *CRÉDITO REACTIVACION \n\nCliente: Julia Mayahua Rodriguez \nSucursal:  orizaba\nMonto solicitado: \$4000\nProducto:  I30\nGiro de negocio:  Venta de antojitos elotes chileatoles\nTeléfono: 2721098057\nDía de pago: Diario";

    expect(RequestSubmission::read($text))->toBe([
        'fields' => [
            'tipo' => 'Reactivación',
            'cliente' => 'Julia Mayahua Rodriguez',
            'sucursal' => 'orizaba',
            'monto_solicitado' => '$4000',
            'producto' => 'I30',
            'giro_de_negocio' => 'Venta de antojitos elotes chileatoles',
            'telefono' => '2721098057',
            'dia_de_pago' => 'Diario',
        ],
        'group' => false,
    ]);
});

test('a client with a heading is enough, a client alone is not', function () {
    expect(RequestSubmission::read("Solicitud de crédito\nCliente: Ana Pérez")['fields'] ?? null)->toBe(['cliente' => 'Ana Pérez'])
        ->and(RequestSubmission::read('Cliente: Ana Pérez'))->toBeNull()
        ->and(RequestSubmission::read("Sucursal: Tula\nProducto: S12"))->toBeNull();
});

test('ordinary texts and announced results are not requests', function (?string $text) {
    expect(RequestSubmission::read($text))->toBeNull();
})->with([
    'greeting' => ['Buenas tardes, ¿me apoyan con la solicitud de mi clienta?'],
    'thanks' => ['Gracias 🙏'],
    'authorization' => ["*Se autoriza crédito nuevo*\nCliente: Ana\nMonto autorizado: \$3000"],
    'null' => [null],
]);

test('the amounts of a resolution are never taken from a request', function () {
    expect(RequestSubmission::read("Cliente: Ana\nSucursal: Tula\nMonto autorizado: \$3000")['fields'] ?? null)
        ->toBe(['cliente' => 'Ana', 'sucursal' => 'Tula']);
});

test('custom labels and times on one line are not split', function () {
    $values = [];

    foreach ((new RequestFieldParser)->parse("Nombre del aval: Pedro\nDía de pago: Lunes a las 10:30") as $row) {
        $values[$row['key']] = $row['value'];
    }

    expect($values)->toBe(['nombre_del_aval' => 'Pedro', 'dia_de_pago' => 'Lunes a las 10:30']);
});

test('a rejection with a leading blank line and spaces is announced as such', function () {
    $text = "\n  Solicitud rechazada \n Cliente: Ana Leticia Lezama \n Con base a política: no cumple con el ciclo";

    expect(RequestOutcomeEvidence::announced($text))->toBe(ConversationOutcome::Rejected)
        ->and(RequestResolution::read($text)['outcome'] ?? null)->toBe(ConversationOutcome::Rejected);
});

test('an authorization written on one line is approved with its amount', function (string $text) {
    expect(RequestOutcomeEvidence::fromText($text))->toBe(['outcome' => ConversationOutcome::Approved, 'amount' => '3000.00'])
        ->and(RequestResolution::read($text)['latest']['monto_autorizado'] ?? null)->toBe('$3,000');
})->with([
    'one line' => ['*Se autoriza crédito nuevo* Producto: S12 Monto autorizado: $3000'],
    'several lines' => ["*Se autoriza crédito nuevo*\nProducto: S12\nMonto autorizado: \$3000"],
    'quote pasted on top' => ["> Cliente: Ana\n> Monto solicitado: \$3000\n\n*Se autoriza crédito nuevo* Producto: S12 Monto autorizado: \$3000"],
]);
