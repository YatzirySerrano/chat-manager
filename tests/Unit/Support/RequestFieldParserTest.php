<?php

use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestFieldParser;

/*
| «Tomar datos de la solicitud»: the «Etiqueta: valor» lines of a credit
| request written by a field manager become suggestions the analyst reviews.
*/

function parsedValues(string $text): array
{
    $values = [];

    foreach ((new RequestFieldParser)->parse($text) as $row) {
        $values[$row['key']] = $row['value'];
    }

    return $values;
}

test('a request written in the usual format is read field by field', function () {
    $text = "Solicitud de crédito\nNuevo\n\nCliente: Beatriz Guerrero Olvera \nSucursal: Tula \nMonto anterior:  \$\nMonto solicitado: \$5,000\nCiclo: 01\nProducto: s12\nGiro de negocio Tortillas\nTeléfono: 773 153 6979";

    $rows = (new RequestFieldParser)->parse($text);

    expect(array_column($rows, 'label'))->toBe(['Tipo', 'Cliente', 'Sucursal', 'Monto anterior', 'Monto solicitado', 'Ciclo', 'Producto', 'Giro de negocio', 'Teléfono'])
        ->and(parsedValues($text))->toBe([
            'tipo' => 'Nuevo',
            'cliente' => 'Beatriz Guerrero Olvera',
            'sucursal' => 'Tula',
            // A «$» left blank is no value: the row is offered empty.
            'monto_anterior' => '',
            'monto_solicitado' => '$5,000',
            'ciclo' => '01',
            'producto' => 's12',
            'giro_de_negocio' => 'Tortillas',
            'telefono' => '773 153 6979',
        ])
        ->and(collect($rows)->every(fn (array $row): bool => $row['known']))->toBeTrue();
});

test('WhatsApp markers, emojis, bullets and accents do not get in the way', function () {
    expect(parsedValues("▶️ *CLIENTE* : _Ana Pérez_ ✅\n• *Día de pago:* Lunes\n- telefono 55 1234 5678\nMonto solicitado: \$ 8,000"))->toBe([
        'cliente' => 'Ana Pérez',
        'dia_de_pago' => 'Lunes',
        'telefono' => '55 1234 5678',
        'monto_solicitado' => '$8,000',
    ]);
});

test('the request type is read from the heading or from its own line', function (string $text, string $type) {
    expect(parsedValues($text)['tipo'] ?? null)->toBe($type);
})->with([
    'under the heading' => ["*Solicitud de crédito*\nReactivación\nCliente: Ana", 'Reactivación'],
    'on the heading line' => ['Solicitud de crédito refinanciamiento', 'Refinanciamiento'],
    'renovación' => ["Solicitud de credito\nRENOVACION", 'Renovación'],
    'adicional' => ["Solicitud\nAdicional 💰", 'Adicional'],
]);

test('labels nobody listed are offered as custom fields', function () {
    $rows = (new RequestFieldParser)->parse("Domicilio: Calle 5 #12\nNombre del aval: Pedro");

    expect($rows)->toBe([
        ['label' => 'Domicilio', 'key' => 'domicilio', 'value' => 'Calle 5 #12', 'known' => false],
        ['label' => 'Nombre del aval', 'key' => 'nombre_del_aval', 'value' => 'Pedro', 'known' => false],
    ]);
});

test('sentences, times and links are not labeled lines', function () {
    expect((new RequestFieldParser)->parse("Buenas tardes, le envío la solicitud\nNos vemos a las 10:30\nhttps://example.com/x\n¿Me confirma por favor?"))->toBe([]);
});

test('a message reads as a request with two labeled lines with a value', function () {
    $parser = new RequestFieldParser;

    expect($parser->looksLikeRequest("Cliente: Ana\nProducto: S12"))->toBeTrue()
        ->and($parser->looksLikeRequest("Cliente: Ana\nGracias"))->toBeFalse()
        ->and($parser->looksLikeRequest("Cliente:\nProducto: \$"))->toBeFalse()
        ->and($parser->looksLikeRequest(null))->toBeFalse();
});

test('labels are recognized however they are written', function (string $label, string $key) {
    expect(RequestFieldCatalog::keyFor($label))->toBe($key);
})->with([
    ['*Cliente*', 'cliente'],
    ['CLIENTE ', 'cliente'],
    ['Teléfono', 'telefono'],
    ['Monto  Solicitado', 'monto_solicitado'],
    ['▶️ Giro de negocio', 'giro_de_negocio'],
    ['Dia de pago', 'dia_de_pago'],
    ['Domicilio', 'domicilio'],
    ['✅', ''],
]);
