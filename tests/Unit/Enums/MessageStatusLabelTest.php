<?php

use App\Enums\MessageStatus;

test('the labels follow the single message status terminology', function (MessageStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    'received' => [MessageStatus::Received, 'Recibido'],
    'queued' => [MessageStatus::Queued, 'Enviando'],
    'sending' => [MessageStatus::Sending, 'Enviando'],
    'accepted' => [MessageStatus::Accepted, 'Enviado'],
    'sent' => [MessageStatus::Sent, 'Enviado'],
    'delivered' => [MessageStatus::Delivered, 'Entregado'],
    'read' => [MessageStatus::Read, 'Leído'],
    'failed' => [MessageStatus::Failed, 'Fallido'],
]);

test('the stored values and the progression do not change with the labels', function () {
    expect(array_map(fn (MessageStatus $status): string => $status->value, MessageStatus::cases()))
        ->toBe(['received', 'queued', 'sending', 'accepted', 'sent', 'delivered', 'read', 'failed'])
        ->and(MessageStatus::Queued->rank())->toBeLessThan(MessageStatus::Sending->rank())
        ->and(MessageStatus::Accepted->rank())->toBeLessThan(MessageStatus::Sent->rank());
});
