<?php

use App\Models\Contact;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Services\QuickReplyRenderer;

function renderQuickReply(string $body, array $context): array
{
    return app(QuickReplyRenderer::class)->render($body, $context);
}

test('every catalog variable is replaced by its value', function () {
    $result = renderQuickReply(
        'Hola {contacto.nombre}, soy {agente.nombre} de {departamento.nombre} en {empresa.nombre}.',
        [
            'contacto.nombre' => 'Ana',
            'agente.nombre' => 'Carlos',
            'departamento.nombre' => 'Ventas',
            'empresa.nombre' => 'Acme',
        ],
    );

    expect($result)->toBe(['text' => 'Hola Ana, soy Carlos de Ventas en Acme.', 'missing' => []]);
});

test('variables without a value are left blank, tidied up and reported', function (string $body, string $expected) {
    $result = renderQuickReply($body, ['contacto.nombre' => null, 'agente.nombre' => 'Carlos', 'departamento.nombre' => '  ']);

    expect($result['text'])->toBe($expected)
        ->and($result['missing'])->toEqualCanonicalizing(array_values(array_filter(
            ['contacto.nombre', 'departamento.nombre', 'empresa.nombre'],
            fn (string $variable): bool => str_contains($body, '{'.$variable.'}'),
        )));
})->with([
    'before a comma' => ['Hola {contacto.nombre}, soy {agente.nombre}.', 'Hola, soy Carlos.'],
    'between words' => ['Gracias {contacto.nombre} por escribir', 'Gracias por escribir'],
    'at the start of a line' => ["Hola\n{contacto.nombre} bienvenido", "Hola\nbienvenido"],
    'several in a row' => ['Atiende {departamento.nombre} {empresa.nombre} hoy', 'Atiende hoy'],
    'after an opening sign' => ['¡{contacto.nombre} hola!', '¡hola!'],
    'whitespace-only value' => ['Área: {departamento.nombre}.', 'Área:.'],
]);

test('text that is not a catalog variable is left untouched', function () {
    $body = "Total {total} {1+1} {{agente.nombre}}\n  sangría intencional";

    expect(renderQuickReply($body, ['agente.nombre' => 'Carlos']))
        ->toBe(['text' => "Total {total} {1+1} {Carlos}\n  sangría intencional", 'missing' => []]);
});

test('values are inserted as plain text and never evaluated or expanded again', function () {
    $result = renderQuickReply('Hola {contacto.nombre} y {agente.nombre}', [
        'contacto.nombre' => "{agente.nombre} <b>Ana</b>\n\${7*7}",
        'agente.nombre' => 'Carlos',
    ]);

    expect($result)->toBe(['text' => 'Hola {agente.nombre} <b>Ana</b> ${7*7} y Carlos', 'missing' => []]);
});

test('the context uses the agent, contact, department and platform name', function () {
    app(PlatformSettings::class)->update(['general' => ['platform_name' => 'Acme Soporte']]);
    $agent = User::factory()->create(['name' => 'Carlos Ruiz']);
    $contact = Contact::factory()->create(['name' => 'Ana López']);
    $department = Department::factory()->create(['name' => 'Ventas']);
    $reply = QuickReply::factory()->make(['body' => '{contacto.nombre}|{agente.nombre}|{departamento.nombre}|{empresa.nombre}']);

    $renderer = app(QuickReplyRenderer::class);

    expect($renderer->render($reply, $renderer->context($agent, $contact, $department)))
        ->toBe(['text' => 'Ana López|Carlos Ruiz|Ventas|Acme Soporte', 'missing' => []]);
});
