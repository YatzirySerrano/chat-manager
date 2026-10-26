<?php

use App\Enums\AttachmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportFileStatus;
use App\Enums\HistoryImportRowState;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Whaticket history importer, end to end with synthetic fixtures: upload →
| inventory → classification → confirmation → execution → report.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
});

test('uploading files only fills the inventory: nothing is imported until confirmation', function () {
    $conversations = Conversation::query()->count();
    $contacts = Contact::query()->count();

    $batch = historyStandardBatch($this->admin);

    expect(Conversation::query()->count())->toBe($conversations)
        ->and(Contact::query()->count())->toBe($contacts)
        ->and(Message::query()->count())->toBe(0)
        ->and($batch->status)->toBe(DataTransferStatus::Draft)
        ->and(HistoryImportFile::query()->where('data_transfer_id', $batch->id)->pluck('status')->all())
        ->each->toBe(HistoryImportFileStatus::Parsed);

    $inventory = $this->actingAs($this->admin)->getJson(route('admin.history-import.inventory.index', $batch))->assertOk();

    expect($inventory->json('meta.total'))->toBe(5)
        ->and(collect($inventory->json('data'))->pluck('source_key')->all())
        ->toEqualCanonicalizing(['ticket:1001', 'ticket:1002', 'ticket:1003', 'ticket:1004', 'ticket:1005']);

    $first = historyRow($batch, 'ticket:1001');
    expect($first->message_count)->toBe(5)
        ->and($first->inbound_count)->toBe(3)
        ->and($first->outbound_count)->toBe(2)
        ->and($first->attachments_total)->toBe(2)
        ->and($first->attachments_available)->toBe(1)
        ->and($first->contact_phone)->toBe('+525500000001')
        ->and($first->source_agent)->toBe('Agente Ficticio A')
        ->and($first->source_department)->toBe('Ventas')
        ->and($first->source_tags)->toBe(['VIP', 'Seguimiento'])
        ->and(historyRow($batch, 'ticket:1003')->message_count)->toBe(0)
        ->and(historyRow($batch, 'ticket:1003')->recommendation)->toBe('exclude')
        ->and(historyRow($batch, 'ticket:1005')->is_group)->toBeTrue();

    $messagesFile = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->where('kind', 'messages')->firstOrFail();
    expect($messagesFile->rows_total)->toBe(13)
        ->and($messagesFile->rows_rejected)->toBe(1)
        ->and($messagesFile->issues['invalid_date']['count'])->toBe(1)
        ->and($messagesFile->unknown_headers)->toBe([]);

    $chatsFile = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->where('kind', 'chats')->firstOrFail();
    expect($chatsFile->unknown_headers)->toContain('userId', 'csat')
        ->and($chatsFile->format_version)->toContain('no-verificado');
});

test('importing one conversation creates a closed, imported conversation with its messages in order', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, historyRow($batch, 'ticket:1001')]), ['decision' => 'import'])->assertOk();

    historyConfirm($this->admin, $batch);

    $batch->refresh();
    expect($batch->status)->toBe(DataTransferStatus::Completed)
        ->and($batch->summary['imported'])->toBe(1)
        ->and($batch->summary['excluded'])->toBe(4);

    $conversation = Conversation::query()->where('imported_by_transfer_id', $batch->id)->sole();
    expect($conversation->origin)->toBe(ConversationOrigin::Whaticket)
        ->and($conversation->status)->toBe(ConversationStatus::Closed)
        ->and($conversation->history_only_at)->toBeNull()
        ->and($conversation->last_inbound_at)->toBeNull()
        ->and($conversation->closed_at->toDateTimeString())->toBe('2026-03-01 18:00:00')
        ->and($conversation->created_at->toDateTimeString())->toBe('2026-03-01 16:00:00')
        ->and(Connection::query()->find($conversation->connection_id)->isDeleted())->toBeTrue();

    $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($messages)->toHaveCount(5)
        ->and($messages->pluck('created_at')->map->toDateTimeString()->all())
        ->toBe(['2026-03-01 16:00:30', '2026-03-01 16:02:00', '2026-03-01 16:05:00', '2026-03-01 16:07:00', '2026-03-01 16:09:00'])
        ->and($messages->first()->direction)->toBe(MessageDirection::Inbound)
        ->and($messages->first()->status)->toBe(MessageStatus::Received)
        ->and($messages[1]->status)->toBe(MessageStatus::Sent)
        ->and($messages[1]->delivered_at)->toBeNull()
        ->and($messages[1]->read_at)->toBeNull()
        ->and($messages[1]->body)->toBe("Hola, con gusto.\n¿Qué producto le interesa?")
        ->and($messages[1]->metadata['import']['sender_name'] ?? null)->toBeNull()
        ->and($messages[1]->metadata['import']['source'])->toBe('whaticket')
        ->and($messages->last()->body)->toBe('Gracias alert(1)')
        ->and($messages->pluck('whatsapp_message_id')->filter()->all())->toBe([]);

    $image = MessageAttachment::query()->where('message_id', $messages[2]->id)->sole();
    $audio = MessageAttachment::query()->where('message_id', $messages[3]->id)->sole();
    expect($image->status)->toBe(AttachmentStatus::Available)
        ->and($image->mime_type)->toBe('image/png')
        ->and(Storage::disk($image->disk)->exists($image->path))->toBeTrue()
        ->and($audio->status)->toBe(AttachmentStatus::Unavailable)
        ->and($audio->error_code)->toBe('historical_missing')
        ->and($audio->path)->toBeNull();

    $contact = Contact::query()->findOrFail($conversation->contact_id);
    expect($contact->phone_e164)->toBe('+525500000001')
        ->and($contact->saved_at)->toBeNull()
        ->and($contact->identities()->count())->toBe(0);

    // Staging purged, stored copies deleted, excluded rows reduced to a reference.
    expect(HistoryImportMessage::query()->where('data_transfer_id', $batch->id)->count())->toBe(0)
        ->and(HistoryImportFile::query()->where('data_transfer_id', $batch->id)->whereNotNull('path')->count())->toBe(0)
        ->and(historyRow($batch, 'ticket:1002')->state)->toBe(HistoryImportRowState::Skipped)
        ->and(historyRow($batch, 'ticket:1002')->contact_name)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::HistoryImportCompleted)->sole()->properties['imported'])->toBe(1);
});
