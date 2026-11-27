<?php

use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Rules\QuickReplyBody;
use App\Services\QuickReplyRenderer;
use Illuminate\Support\Facades\Validator;

/*
| A quick reply inserted in a conversation is completed with the request data
| taken from its messages: the «solicitud.*» variables and the «Etiqueta:»
| lines left empty. It is still only inserted for review, never sent.
*/

/**
 * @param  array<string, string>  $values
 */
function fillLines(string $text, array $values): array
{
    return app(QuickReplyRenderer::class)->fillRequestLines($text, $values);
}

test('empty «Etiqueta:» lines get the captured value of the same label', function () {
    $body = "*Se autoriza reactivación de crédito* \n\nProducto: \nCliente: \nMonto autorizado: \$\nDesembolso: \$\nCantidad a pagar: \$";

    expect(fillLines($body, ['producto' => 'S12', 'cliente' => 'Beatriz Guerrero Olvera', 'monto_solicitado' => '$5,000']))->toBe([
        'text' => "*Se autoriza reactivación de crédito* \n\nProducto: S12\nCliente: Beatriz Guerrero Olvera\nMonto autorizado: \$\nDesembolso: \$\nCantidad a pagar: \$",
        'filled' => ['producto', 'cliente'],
    ]);
});

test('labels match however they are written and the dollar sign is never doubled', function (string $line, string $expected) {
    expect(fillLines($line, ['cliente' => 'Ana', 'monto_solicitado' => '$5,000', 'monto_anterior' => '3,000', 'sucursal' => 'Tula', 'telefono' => '773 153 6979'])['text'])->toBe($expected);
})->with([
    'bold label' => ['*Cliente:*', '*Cliente:* Ana'],
    'upper case with a space before the colon' => ['SUCURSAL : ', 'SUCURSAL : Tula'],
    'accents do not matter' => ['Telefono:', 'Telefono: 773 153 6979'],
    'dollar in the template and in the value' => ['Monto solicitado: $', 'Monto solicitado: $5,000'],
    'dollar in the template only' => ['Monto anterior: $ ', 'Monto anterior: $3,000'],
    'dollar in the value only' => ['Monto solicitado:', 'Monto solicitado: $5,000'],
    'a line that already has a value' => ['Cliente: Pedro', 'Cliente: Pedro'],
    'a label nobody captured' => ['STATUS :', 'STATUS :'],
    'several labels on one line' => ['SUCURSAL : CLIENTE: STATUS :', 'SUCURSAL : CLIENTE: STATUS :'],
]);

test('the request variables are accepted in a reply and filled from the conversation', function () {
    expect(Validator::make(['body' => 'Cliente {solicitud.cliente}, producto {solicitud.producto}'], ['body' => [new QuickReplyBody]])->passes())->toBeTrue();

    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();
    $conversation = Conversation::factory()->inDepartment($department)->assignedTo($agent)->create([
        'request_fields' => [
            ['key' => 'producto', 'label' => 'Producto', 'value' => 'S12', 'message_id' => null, 'captured_at' => now()->toIso8601String()],
            ['key' => 'cliente', 'label' => 'Cliente', 'value' => 'Beatriz', 'message_id' => null, 'captured_at' => now()->toIso8601String()],
        ],
    ]);
    QuickReply::factory()->create(['shortcut' => 'reactivacion', 'body' => "Para {solicitud.cliente} ({solicitud.ciclo})\nProducto:\nMonto autorizado: \$"]);

    $this->actingAs($agent)
        ->getJson(route('quick-replies.available', ['q' => 'reactivacion', 'conversation_id' => $conversation->id]))
        ->assertOk()
        ->assertJsonPath('data.0.text', "Para Beatriz ()\nProducto: S12\nMonto autorizado: \$")
        ->assertJsonPath('data.0.missing', ['solicitud.ciclo'])
        ->assertJsonPath('data.0.filled', ['producto']);

    // Without the conversation (or with one out of reach) nothing is filled.
    $outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();

    $this->actingAs($outsider)
        ->getJson(route('quick-replies.available', ['q' => 'reactivacion', 'conversation_id' => $conversation->id]))
        ->assertOk()
        ->assertJsonPath('data.0.text', "Para ()\nProducto:\nMonto autorizado: \$")
        ->assertJsonPath('data.0.filled', []);
});

test('an authorization reply is completed with the resolution typed once', function () {
    $body = "*Se autoriza reactivación de crédito*\nProducto:\nCliente:\nMonto autorizado: \$\nDesembolso: \$\nCantidad a pagar: \$";
    $values = ['producto' => 'S12 JUEVES', 'cliente' => 'Beatriz', 'monto_autorizado' => '$3,000', 'desembolso' => '$2,130', 'cantidad_a_pagar' => '$398'];

    expect(fillLines($body, $values))->toBe([
        'text' => "*Se autoriza reactivación de crédito*\nProducto: S12 JUEVES\nCliente: Beatriz\nMonto autorizado: \$3,000\nDesembolso: \$2,130\nCantidad a pagar: \$398",
        'filled' => ['producto', 'cliente', 'monto_autorizado', 'desembolso', 'cantidad_a_pagar'],
    ]);
});

test('resolution lines match with typos, without the colon and with a bare «Monto»', function (string $line, string $expected) {
    $values = ['monto_autorizado' => '$3,000', 'monto_solicitado' => '$5,000', 'desembolso' => '$2,130', 'cantidad_a_pagar' => '$398'];

    expect(fillLines("Se autoriza renovación\n{$line}", $values)['text'])->toBe("Se autoriza renovación\n{$expected}");
})->with([
    'bare amount in an authorization' => ['Monto: $', 'Monto: $3,000'],
    'typo' => ['*Desenbolso:* $', '*Desenbolso:* $2,130'],
    'truncated' => ['Cant a pagar: $', 'Cant a pagar: $398'],
    'no colon' => ['Desembolso $', 'Desembolso $2,130'],
    'misspelled' => ['Monto autorisado: $', 'Monto autorisado: $3,000'],
]);

test('a bare «Monto» outside an authorization is the amount requested', function () {
    expect(fillLines('Monto: $', ['monto_autorizado' => '$3,000', 'monto_solicitado' => '$5,000'])['text'])->toBe('Monto: $5,000');
});

test('the resolution variables are filled from the conversation', function () {
    $conversation = Conversation::factory()->create(['request_fields' => [
        ['key' => 'monto_autorizado', 'label' => 'Monto autorizado', 'value' => '$3,000'],
        ['key' => 'desembolso', 'label' => 'Desembolso', 'value' => '$2,130'],
        ['key' => 'cantidad_a_pagar', 'label' => 'Cantidad a pagar', 'value' => '$398'],
    ]]);
    $renderer = app(QuickReplyRenderer::class);

    expect($renderer->render('{solicitud.monto_autorizado} / {solicitud.desembolso} / {solicitud.cantidad_a_pagar}', $renderer->context(conversation: $conversation))['text'])
        ->toBe('$3,000 / $2,130 / $398');
});
