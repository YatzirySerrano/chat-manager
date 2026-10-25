<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

test('every validation rule Laravel ships has a Spanish message', function () {
    $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $spanish = require lang_path('es/validation.php');

    $missing = array_diff(
        array_keys(Arr::dot(Arr::except($english, ['custom', 'attributes']))),
        array_keys(Arr::dot(Arr::except($spanish, ['custom', 'attributes']))),
    );

    expect($missing)->toBe([]);
});

test('rules without a message of their own answer in Spanish with the field name', function (array $data, array $rules, string $message) {
    expect(Validator::make($data, $rules)->errors()->first())->toBe($message);
})->with([
    'uuid' => [['client_reference' => 'x'], ['client_reference' => 'uuid'], 'El campo referencia del envío debe ser un identificador UUID válido.'],
    'required_without' => [[], ['phone' => 'required_without:email'], 'El campo teléfono es obligatorio cuando no se indica correo electrónico.'],
    'prohibited' => [['website' => 'spam'], ['website' => 'prohibited'], 'El campo sitio web no está permitido.'],
    'size' => [['role_change_fingerprint' => 'abc'], ['role_change_fingerprint' => 'size:64'], 'El campo confirmación del cambio de rol debe tener 64 caracteres.'],
    'nested attribute' => [['holidays' => [['label' => '']]], ['holidays.*.label' => 'required'], 'El campo nombre del día festivo es obligatorio.'],
]);
