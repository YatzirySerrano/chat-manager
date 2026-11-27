<?php

use App\Enums\ConversationOutcome;
use App\Support\RequestOutcomeEvidence;

test('an authorization quick reply suggests an approval with its amount', function (string $text, ?string $amount) {
    expect(RequestOutcomeEvidence::fromText($text))->toBe(['outcome' => ConversationOutcome::Approved, 'amount' => $amount]);
})->with([
    'reactivation' => ["*Se autoriza reactivación de crédito*\n\nProducto: S12\nMonto autorizado: \$3000\nPlazo: 12 semanas", '3000.00'],
    'thousands' => ["Se autoriza crédito nuevo\nMonto autorizado: \$3,000", '3000.00'],
    'spaces and cents' => ["*Se autoriza refinanciamiento*\n*Monto autorizado:* \$ 3,000.00", '3000.00'],
    'cents' => ["Se autoriza adición\nMonto autorizado: 12,500.50 MXN", '12500.50'],
    'dot thousands' => ["Se autoriza adición\nMonto autorizado: \$3.000", '3000.00'],
    'emoji and greeting' => ["Buen día\n✅ SE AUTORIZA REACTIVACIÓN DE CRÉDITO", null],
    'without amount' => ['Se autoriza reactivación de crédito', null],
]);

test('a rejection is suggested as such, never as an approval', function (string $text) {
    expect(RequestOutcomeEvidence::fromText($text))->toBe(['outcome' => ConversationOutcome::Rejected, 'amount' => null]);
})->with([
    'quick reply' => ["*Solicitud rechazada*\nMotivo: buró"],
    'negated authorization' => ['No se autoriza el crédito por ahora'],
    'credit not approved' => ['Crédito no aprobado'],
]);

test('ordinary replies suggest nothing', function (?string $text) {
    expect(RequestOutcomeEvidence::fromText($text))->toBeNull();
})->with([
    'greeting' => ['Hola, ¿en qué te ayudo?'],
    'announcement deep in the text' => ["Hola\nTe comento\nSe autoriza reactivación de crédito"],
    'empty' => [''],
    'null' => [null],
]);

test('amounts written by people are read as decimals', function (?string $text, ?string $amount) {
    expect(RequestOutcomeEvidence::amount($text))->toBe($amount);
})->with([
    ['$3,000', '3000.00'],
    ['3000', '3000.00'],
    ['$ 3,000.00', '3000.00'],
    ['3 000', '3000.00'],
    ['3000,5', '3000.50'],
    ['$1,250,000', '1250000.00'],
    ['sin monto', null],
    ['99999999999', null],
    [null, null],
]);

test('a decision still to come announces nothing', function (string $text) {
    expect(RequestOutcomeEvidence::announced($text))->toBeNull();
})->with([
    "*Se autorizará crédito* en cuanto llegue el comprobante\nMonto autorizado: $3000",
    'Se autorizara la reactivación mañana',
    "Crédito por autorizar\nMonto: $3000",
    'Solicitud en revisión',
    'Se va a autorizar el crédito',
]);
