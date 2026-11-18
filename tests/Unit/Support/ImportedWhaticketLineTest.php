<?php

use App\Support\ImportedWhaticketLine;

test('the Whaticket signature becomes the author and leaves the text alone', function () {
    expect(ImportedWhaticketLine::signature("Jennifer:\nDAME UNOS MINUTOS Y LO VALIDO"))
        ->toBe(['name' => 'Jennifer', 'body' => 'DAME UNOS MINUTOS Y LO VALIDO'])
        ->and(ImportedWhaticketLine::signature("*Angeles S:*\nPENDIENTE PLANTILLA/DOCU Y UBI"))
        ->toBe(['name' => 'Angeles S', 'body' => 'PENDIENTE PLANTILLA/DOCU Y UBI'])
        ->and(ImportedWhaticketLine::signature("Jennifer:\nME FALTA\n+VIDEO CON FECHA BIEN"))
        ->toBe(['name' => 'Jennifer', 'body' => "ME FALTA\n+VIDEO CON FECHA BIEN"]);
});

test('ordinary text with a colon is never taken for a signature', function () {
    expect(ImportedWhaticketLine::signature('Producto: S12'))->toBeNull()
        ->and(ImportedWhaticketLine::signature("Se autoriza reactivación de crédito \n\nProducto: S12"))->toBeNull()
        ->and(ImportedWhaticketLine::signature('Gracias'))->toBeNull()
        ->and(ImportedWhaticketLine::signature(null))->toBeNull();
});

test('a Whaticket operation line says who did what', function () {
    expect(ImportedWhaticketLine::event('Jennifer resuelto la conversación'))
        ->toBe(['actor' => 'Jennifer', 'action' => 'resolvió la conversación'])
        ->and(ImportedWhaticketLine::event('Angeles S aceptado la conversación'))
        ->toBe(['actor' => 'Angeles S', 'action' => 'aceptó la conversación'])
        ->and(ImportedWhaticketLine::event('Angeles S devuelto la conversación para esperar'))
        ->toBe(['actor' => 'Angeles S', 'action' => 'devolvió la conversación para esperar'])
        ->and(ImportedWhaticketLine::event('Ok gracias'))->toBeNull();
});
