<?php

use App\Enums\ConversationOutcome;
use App\Support\RequestFields\FuzzyText;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestFieldParser;
use App\Support\RequestFields\RequestResolution;
use App\Support\RequestOutcomeEvidence;

/*
| Labels, headings and openings are typed in a hurry on WhatsApp: accents,
| case, markers, emojis, missing colons, typos and truncations must not
| change what they name.
*/

test('a misspelled or truncated label names the known field', function (string $label, string $key) {
    expect(RequestFieldCatalog::keyFor($label))->toBe($key);
})->with([
    ['Monto autorisado', 'monto_autorizado'],
    ['monto aut', 'monto_autorizado'],
    ['Monto autorizad', 'monto_autorizado'],
    ['*MONTO AUTORIZADO*', 'monto_autorizado'],
    ['Monto autorizo', 'monto_autorizado'],
    ['DESEMBOLZO', 'desembolso'],
    ['desenbolso', 'desembolso'],
    ['Desembolso ▶️', 'desembolso'],
    ['Cantidad a pagar', 'cantidad_a_pagar'],
    ['cant. a pagar', 'cantidad_a_pagar'],
    ['Cantidad a pagr', 'cantidad_a_pagar'],
    ['Telefono', 'telefono'],
    ['TEL', 'telefono'],
    ['Cel.', 'telefono'],
    ['Teléfno', 'telefono'],
    ['Suc.', 'sucursal'],
    ['Sucursl', 'sucursal'],
    ['Clientes', 'cliente'],
    ['Cliente ', 'cliente'],
    ['Giro del negocio', 'giro_de_negocio'],
    ['Producto', 'producto'],
    ['Prodcto', 'producto'],
    ['Monto solisitado', 'monto_solicitado'],
    ['Dia de pago', 'dia_de_pago'],
    ['Plaso', 'plazo'],
]);

test('labels that are something else stay custom fields', function (string $label, string $key) {
    expect(RequestFieldCatalog::keyFor($label))->toBe($key);
})->with([
    ['Domicilio', 'domicilio'],
    ['Nombre del aval', 'nombre_del_aval'],
    ['Tipo de negocio', 'tipo_de_negocio'],
    ['Status', 'status'],
    ['Motivo', 'motivo'],
]);

test('a bare «Monto» depends on the message it is read in', function () {
    expect(RequestFieldCatalog::keyFor('Monto'))->toBe('monto_solicitado')
        ->and(RequestFieldCatalog::keyFor('Monto', RequestFieldCatalog::RESOLUTION))->toBe('monto_autorizado')
        ->and(RequestFieldCatalog::keyFor('Pago', RequestFieldCatalog::RESOLUTION))->toBe('cantidad_a_pagar')
        ->and(RequestFieldCatalog::keyFor('Cuota', RequestFieldCatalog::RESOLUTION))->toBe('cantidad_a_pagar');
});

test('a hurried request is read with its heading, type and labels', function () {
    $values = [];

    foreach ((new RequestFieldParser)->parse("*Solisitud de cred*\nReactibacion\nClienta: Ana\nMonto: 5000\nSucursl Tula\nTel 773 153 6979") as $row) {
        $values[$row['key']] = $row['value'];
    }

    expect($values)->toBe([
        'tipo' => 'Reactivación',
        'cliente' => 'Ana',
        'monto_solicitado' => '5000',
        'sucursal' => 'Tula',
        'telefono' => '773 153 6979',
    ]);
});

test('request types are read with typos and truncations', function (string $word, ?string $type) {
    expect(RequestFieldParser::typeOf(RequestFieldCatalog::normalize($word)))->toBe($type);
})->with([
    ['Reactivación', 'Reactivación'],
    ['reactibacion', 'Reactivación'],
    ['RENOVACION', 'Renovación'],
    ['renobacion', 'Renovación'],
    ['refinanciam', 'Refinanciamiento'],
    ['Adicional', 'Adicional'],
    ['nueva', 'Nuevo'],
    ['gracias', null],
]);

test('openings with typos announce the result', function (string $text, ConversationOutcome $outcome) {
    expect(RequestOutcomeEvidence::announced($text))->toBe($outcome);
})->with([
    ['*SE AUTORISA REACTIBACION DE CRED*', ConversationOutcome::Approved],
    ['Se autorizó la renovación', ConversationOutcome::Approved],
    ['se aprobo tu credito', ConversationOutcome::Approved],
    ['Credit autorizado', ConversationOutcome::Approved],
    ['✅ Solicitud autorisada', ConversationOutcome::Approved],
    ['Solicitud rechasada', ConversationOutcome::Rejected],
    ['*Rechazado*', ConversationOutcome::Rejected],
    ['No se autoriza por ahora', ConversationOutcome::Rejected],
    ['No autorizado', ConversationOutcome::Rejected],
    ['Solisitud rechazada', ConversationOutcome::Rejected],
]);

test('the authorized amount is read from any label that means it', function (string $text, ?string $amount) {
    expect(RequestOutcomeEvidence::fromText($text)['amount'] ?? null)->toBe($amount);
})->with([
    ["Se autoriza renovación\nMonto: \$3,000", '3000.00'],
    ["Se autoriza renovación\nMonto aut \$ 3.000", '3000.00'],
    ["Se autoriza renovación\n*Monto autorisado:* 3000", '3000.00'],
    ["Se autoriza renovación\nMonto autorizado: 3 mil", '3000.00'],
    ["Se autoriza renovación\nMonto solicitado: \$5,000", null],
]);

test('an authorization reads into the resolution fields', function () {
    expect(RequestResolution::read("*Se autoriza reactivación de crédito*\nProducto: S12 JUEVES\nCliente: BEATRIZ GUERRERO OLVERA\nMonto autorizado: \$3000\nDesembolso: \$2,130\nCantidad a pagar: \$398\nPlazo: 12 semanas"))->toBe([
        'outcome' => ConversationOutcome::Approved,
        'latest' => [
            'monto_autorizado' => '$3,000',
            'desembolso' => '$2,130',
            'cantidad_a_pagar' => '$398',
            'plazo' => '12 semanas',
        ],
        'if_missing' => [
            'producto' => 'S12 JUEVES',
            'cliente' => 'BEATRIZ GUERRERO OLVERA',
            'tipo' => 'Reactivación',
        ],
    ])->and(RequestResolution::read("Hola\nMonto autorizado: \$3000"))->toBeNull();
});

test('words compare with a tolerance that grows with their length', function () {
    expect(FuzzyText::wordDistance('desenbolso', 'desembolso'))->toBe(1)
        ->and(FuzzyText::wordDistance('autorizo', 'autorizado'))->toBe(2)
        ->and(FuzzyText::wordDistance('tel', 'cel'))->toBeNull()
        ->and(FuzzyText::wordDistance('cred', 'credito'))->toBeNull()
        ->and(FuzzyText::wordDistance('cred', 'credito', prefixes: true))->toBe(1)
        ->and(FuzzyText::wordDistance('cre', 'credito', prefixes: true))->toBeNull();
});

test('amounts as people write them, a thousand included', function (string $text, ?string $amount) {
    expect(RequestOutcomeEvidence::amount($text))->toBe($amount);
})->with([
    ['3 mil', '3000.00'],
    ['2.5 mil', '2500.00'],
    ['$3,000 pesos', '3000.00'],
    ['$ 3.000', '3000.00'],
    ['3,000.00', '3000.00'],
]);

test('amounts are shown the way people read them', function () {
    expect(RequestOutcomeEvidence::display('3000.00'))->toBe('$3,000')
        ->and(RequestOutcomeEvidence::display('2130.50'))->toBe('$2,130.50');
});
