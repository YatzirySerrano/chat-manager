<?php

use App\Enums\AuditAction;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\RequestFields\RequestFieldCatalog;

/*
| `requests:backfill`: the requests attended before the automatic captures
| get their request data and their credit result from their messages. A dry
| run writes nothing; --apply fills only what is missing, once.
*/

function backfillConversation(array $attributes = []): Conversation
{
    return Conversation::factory()->closed()->create([
        'last_message_at' => now()->subDays(3),
        ...$attributes,
    ]);
}

function backfillMessage(Conversation $conversation, MessageDirection $direction, string $body, array $attributes = []): Message
{
    return Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => $direction,
        'status' => $direction === MessageDirection::Inbound ? MessageStatus::Received : MessageStatus::Sent,
        'body' => $body,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->agent = User::factory()->agent()->create();
    $this->completedAt = now()->subDays(3)->startOfMinute();

    // A completed request: the field manager's request and the team's authorization.
    $this->approved = backfillConversation(['completed_at' => $this->completedAt, 'closed_by_user_id' => $this->agent->id]);
    backfillMessage($this->approved, MessageDirection::Inbound, 'Hola buenas tardes');
    $this->request = backfillMessage($this->approved, MessageDirection::Inbound, '*Solicito autorización para crédito nuevo:* *Cliente:*Ana Leticia lezama rezendiz *Sucursal:* Atlacomulco *Monto solicitado:*3000 *Producto:* s12');
    $this->authorization = backfillMessage($this->approved, MessageDirection::Outbound, "*Se autoriza crédito nuevo*\nProducto: S12\nMonto autorizado: \$3000\nDesembolso: \$2,130\nCantidad a pagar: \$398");

    // A rejected one, its rejection with a leading blank line.
    $this->rejected = backfillConversation(['completed_at' => $this->completedAt, 'closed_by_user_id' => $this->agent->id]);
    backfillMessage($this->rejected, MessageDirection::Inbound, "Solicitud de crédito\nCliente: Julia Mayahua\nSucursal: Orizaba");
    backfillMessage($this->rejected, MessageDirection::Outbound, "\n Solicitud rechazada \n Cliente: Julia Mayahua \n Con base a política: no cumple");
});

test('a dry run writes nothing', function () {
    $writes = databaseWritesDuring(fn () => $this->artisan('requests:backfill')->assertSuccessful());

    expect($writes)->toBe([])
        ->and($this->approved->fresh()->request_fields)->toBeNull()
        ->and($this->approved->fresh()->outcome)->toBeNull();
});

test('applying fills the request data and the results, dated at the completion', function () {
    $this->artisan('requests:backfill --apply')->assertSuccessful();

    $approved = $this->approved->fresh();
    $rejected = $this->rejected->fresh();

    expect(RequestFieldCatalog::values($approved->request_fields))->toBe([
        'tipo' => 'Nuevo',
        'cliente' => 'Ana Leticia lezama rezendiz',
        'sucursal' => 'Atlacomulco',
        'monto_solicitado' => '3000',
        'producto' => 's12',
        'monto_autorizado' => '$3,000',
        'desembolso' => '$2,130',
        'cantidad_a_pagar' => '$398',
    ])
        ->and($approved->outcome)->toBe(ConversationOutcome::Approved)
        ->and($approved->outcome_amount)->toBe('3000.00')
        ->and($approved->outcome_at->equalTo($this->completedAt))->toBeTrue()
        ->and($approved->outcome_by_user_id)->toBe($this->agent->id)
        ->and($rejected->outcome)->toBe(ConversationOutcome::Rejected)
        ->and($rejected->outcome_amount)->toBeNull()
        ->and(RequestFieldCatalog::values($rejected->request_fields)['cliente'])->toBe('Julia Mayahua');

    $outcomes = AuditLog::query()->where('action', AuditAction::ConversationOutcomeRecorded)->get();

    expect($outcomes)->toHaveCount(2)
        ->and($outcomes->every(fn (AuditLog $log): bool => $log->user_id === null && $log->properties['note'] === 'backfill'))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::ConversationRequestFieldsChanged)->get()
            ->every(fn (AuditLog $log): bool => $log->properties['note'] === 'backfill'))->toBeTrue();
});

test('running it again changes nothing', function () {
    $this->artisan('requests:backfill --apply')->assertSuccessful();
    $audits = AuditLog::query()->count();

    $writes = databaseWritesDuring(fn () => $this->artisan('requests:backfill --apply')->assertSuccessful());

    expect($writes)->toBe([])
        ->and(AuditLog::query()->count())->toBe($audits);
});

test('imported history, «No es solicitud», old activity and stored values are left alone', function () {
    $imported = backfillConversation(['origin' => ConversationOrigin::Whaticket, 'completed_at' => $this->completedAt]);
    backfillMessage($imported, MessageDirection::Inbound, "Solicitud de crédito\nCliente: Rosa\nSucursal: Tula");
    backfillMessage($imported, MessageDirection::Outbound, 'Solicitud rechazada');

    $notRequest = backfillConversation(['not_request_at' => now()->subDays(3), 'completed_at' => $this->completedAt]);
    backfillMessage($notRequest, MessageDirection::Inbound, "Solicitud de crédito\nCliente: Rosa\nSucursal: Tula");

    $old = backfillConversation(['last_message_at' => now()->subDays(60), 'completed_at' => now()->subDays(60)]);
    backfillMessage($old, MessageDirection::Inbound, "Solicitud de crédito\nCliente: Rosa\nSucursal: Tula");

    $this->approved->forceFill([
        'outcome' => ConversationOutcome::Rejected,
        'request_fields' => [['key' => 'cliente', 'label' => 'Cliente', 'value' => 'Ana L.', 'message_id' => null, 'captured_at' => null]],
    ])->save();

    $this->artisan('requests:backfill --apply')->assertSuccessful();

    expect($imported->fresh()->request_fields)->toBeNull()
        ->and($imported->fresh()->outcome)->toBeNull()
        ->and($notRequest->fresh()->request_fields)->toBeNull()
        ->and($notRequest->fresh()->outcome)->toBeNull()
        ->and($old->fresh()->request_fields)->toBeNull()
        ->and($this->approved->fresh()->outcome)->toBe(ConversationOutcome::Rejected)
        ->and(RequestFieldCatalog::values($this->approved->fresh()->request_fields))->toBe(['cliente' => 'Ana L.']);
});

test('an earlier --since reaches older activity', function () {
    $old = backfillConversation(['last_message_at' => now()->subDays(60)]);
    backfillMessage($old, MessageDirection::Inbound, "Solicitud de crédito\nCliente: Rosa\nSucursal: Tula");

    $this->artisan('requests:backfill', ['--apply' => true, '--since' => now()->subDays(90)->toDateString()])->assertSuccessful();

    expect(RequestFieldCatalog::values($old->fresh()->request_fields))->toBe(['cliente' => 'Rosa', 'sucursal' => 'Tula']);
});

test('an invalid --since is refused', function () {
    $this->artisan('requests:backfill', ['--since' => 'ayer'])->assertFailed();
});
