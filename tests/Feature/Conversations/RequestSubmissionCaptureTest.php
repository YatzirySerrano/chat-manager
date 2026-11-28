<?php

use App\Enums\AuditAction;
use App\Events\Conversations\ConversationChanged;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Services\Conversations\RequestSubmissionCapture;
use App\Support\RequestFields\RequestFieldCatalog;
use Illuminate\Support\Facades\Event;

/*
| «Datos de la solicitud» fills itself when the field manager writes the
| credit request in the chat: while the conversation has no client, the
| request's known fields are stored (never replacing anything), by the
| system, audited and signalled once the receipt commits.
*/

beforeEach(function () {
    $this->connection = Connection::factory()->operational()->create();
});

test('a request written on one line fills the request data on arrival', function () {
    Event::fake([ConversationChanged::class]);

    $receipt = receiveWhatsapp($this->connection, '5215512345678', '*Solicito autorización para crédito nuevo:* *Cliente:*Ana Leticia lezama rezendiz *Sucursal:* Atlacomulco *Monto solicitado:*3000 *Producto:* s12 *Giro:* ropa *Teléfono:* 7731349188');

    $conversation = $receipt->conversation->fresh();

    expect(RequestFieldCatalog::values($conversation->request_fields))->toBe([
        'tipo' => 'Nuevo',
        'cliente' => 'Ana Leticia lezama rezendiz',
        'sucursal' => 'Atlacomulco',
        'monto_solicitado' => '3000',
        'producto' => 's12',
        'giro_de_negocio' => 'ropa',
        'telefono' => '7731349188',
    ])
        ->and(collect($conversation->request_fields)->pluck('message_id')->unique()->all())->toBe([$receipt->message->id]);

    $audit = AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->sole();

    expect($audit->user_id)->toBeNull()
        ->and($audit->properties['mode'])->toBe('auto')
        ->and($audit->properties['source'])->toBe('inbound_message')
        ->and($audit->properties['message_id'])->toBe($receipt->message->id);

    Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event): bool => $event->reason === 'request_fields' && $event->conversationId === $conversation->id);
});

test('the first request of the conversation stays, a later one changes nothing', function () {
    receiveWhatsapp($this->connection, '5215512345678', "Solicitud de crédito\nCliente: Ana Pérez\nSucursal: Tula");
    $receipt = receiveWhatsapp($this->connection, '5215512345678', "Cliente: Rosa López\nSucursal: Orizaba\nProducto: S12");

    expect(RequestFieldCatalog::values($receipt->conversation->fresh()->request_fields))->toBe(['cliente' => 'Ana Pérez', 'sucursal' => 'Tula'])
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->count())->toBe(1);
});

test('a group credit keeps its first client and still records the group', function () {
    receiveWhatsapp($this->connection, '5215512345678', "Solicito validación de negocio para crédito grupal\nCliente: María Isabel Rodríguez\nMonto solicitado: \$5000");
    $receipt = receiveWhatsapp($this->connection, '5215512345678', "Solicito validación de negocio para crédito grupal\nCliente: Rosa Vargas\nNombre del grupo EMPRENDEDORAS MATA CLARA   Celular :  2781320471");

    expect(RequestFieldCatalog::values($receipt->conversation->fresh()->request_fields))->toBe([
        'cliente' => 'María Isabel Rodríguez',
        'monto_solicitado' => '$5000',
        'grupo' => 'EMPRENDEDORAS MATA CLARA',
    ]);
});

test('data typed by the analyst is never replaced, only completed', function () {
    $first = receiveWhatsapp($this->connection, '5215512345678', 'Hola, buen día');
    $first->conversation->forceFill(['request_fields' => [
        ['key' => 'producto', 'label' => 'Producto', 'value' => 'S16', 'message_id' => null, 'captured_at' => null],
    ]])->save();

    $receipt = receiveWhatsapp($this->connection, '5215512345678', "Cliente: Ana Pérez\nProducto: S12\nSucursal: Tula");

    expect(RequestFieldCatalog::values($receipt->conversation->fresh()->request_fields))->toBe(['producto' => 'S16', 'cliente' => 'Ana Pérez', 'sucursal' => 'Tula']);
});

test('ordinary messages capture nothing', function () {
    $receipt = receiveWhatsapp($this->connection, '5215512345678', 'Buenas tardes, ¿me apoyan con la solicitud de mi clienta?');

    expect($receipt->conversation->fresh()->request_fields)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->exists())->toBeFalse();
});

test('a failing capture never stops the receipt', function () {
    $this->mock(RequestSubmissionCapture::class)->shouldReceive('fromMessage')->andThrow(new RuntimeException('boom'));

    $receipt = receiveWhatsapp($this->connection, '5215512345678', "Solicitud de crédito\nCliente: Ana Pérez\nSucursal: Tula");

    expect($receipt->message->exists)->toBeTrue()
        ->and($receipt->conversation->fresh()->request_fields)->toBeNull();
});
