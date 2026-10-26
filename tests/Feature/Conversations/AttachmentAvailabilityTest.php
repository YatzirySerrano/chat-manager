<?php

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Http\Resources\ConversationMessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;

/*
| The chat tells why a file cannot be shown (a history file that never
| arrived, storage not answering, a failed download): the message resource
| carries the attachment's status and error code, without a usable file.
*/

function unavailableAttachment(?string $path, string $status, ?string $code): array
{
    $message = Message::query()->create([
        'conversation_id' => Conversation::factory()->create()->id,
        'whatsapp_message_id' => 'wamid.test.'.fake()->uuid(),
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Document,
        'status' => 'received',
    ]);
    $attachment = new MessageAttachment([
        'message_id' => $message->id,
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'file_name' => 'contrato.pdf',
        'size_bytes' => 1024,
    ]);
    $attachment->forceFill(['status' => $status, 'error_code' => $code])->save();

    return (new ConversationMessageResource($message->load('attachments')))->resolve()['attachments'][0];
}

test('a missing history file is reported as unavailable with its reason', function () {
    $attachment = unavailableAttachment(null, 'unavailable', 'historical_missing');

    expect($attachment)
        ->available->toBeFalse()
        ->status->toBe('unavailable')
        ->error_code->toBe('historical_missing');
});

test('an available file has no error code', function () {
    $attachment = unavailableAttachment('media/2026/10/file', 'available', null);

    expect($attachment)
        ->available->toBeTrue()
        ->status->toBe('available')
        ->error_code->toBeNull();
});
