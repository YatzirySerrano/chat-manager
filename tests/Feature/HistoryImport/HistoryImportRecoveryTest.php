<?php

use App\Actions\HistoryImport\PruneHistoryImports;
use App\Enums\AttachmentStatus;
use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportFileStatus;
use App\Enums\HistoryImportRowState;
use App\Events\Conversations\ConversationChanged;
use App\Jobs\ExecuteHistoryImport;
use App\Jobs\ParseHistoryImportFile;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\HistoryImportSourceKey;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Migration\History\HistoryFileIngestor;
use App\Services\Migration\History\HistoryImportExecutor;
use App\Services\Migration\History\HistoryInventory;
use App\Services\Migration\History\SafeZip;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Recovery and robustness of the execution (review 5.2): failed
| conversations are retryable with their staging kept, a storage outage
| pauses the batch instead of failing conversations, long conversations
| make progress across runs without orphan uploads, media never crosses
| ZIPs, Chat.txt keys are stable, contact-keyed conversations are extended,
| the inventory is never rebuilt or changed after the confirmation, and
| retention minimizes every expired batch.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
});

/**
 * A batch of one ZIP with a Chat.txt, its company participant marked.
 *
 * @param  array<string, string>  $media
 */
function historyChatBatch(User $admin, string $chat, array $media, string $decision = 'archive'): DataTransfer
{
    $batch = historyBatch($admin);
    historyAddFile($admin, $batch, 'media', historyZipUpload(['Chat de WhatsApp con Cliente.txt' => $chat, ...$media], 'chat-'.md5($chat.implode('', array_keys($media)).random_int(0, PHP_INT_MAX)).'.zip'));
    $row = HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->firstOrFail();
    test()->actingAs($admin)->patchJson(route('admin.history-import.inventory.update', [$batch, $row]), ['business_participants' => ['Empresa Ficticia'], 'decision' => $decision])->assertOk();
    historyMapPlaceholders($admin, $batch);

    return $batch->refresh();
}

function historyChatText(string $name = 'Cliente Ficticio Siete', string $file = 'IMG-20240101-WA0001.jpg'): string
{
    return "01/05/26, 10:00 - {$name}: Hola (ficticio)\n01/05/26, 10:01 - {$name}: {$file} (archivo adjunto)\n01/05/26, 10:02 - Empresa Ficticia: Recibido\n";
}

test('a failed conversation can be retried after fixing its cause; its staging is kept until then', function () {
    $twins = Contact::factory()->count(2)->create(['phone_e164' => '+525500000002', 'saved_at' => null]);

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    $failed = historyRow($batch, 'ticket:1002');
    expect($failed->state)->toBe(HistoryImportRowState::Failed)
        ->and($failed->attempts)->toBe(1)
        ->and($failed->error)->not->toContain('55')
        ->and($batch->refresh()->summary['outcome'])->toBe('completed_with_errors')
        ->and(HistoryImportMessage::query()->where('history_import_conversation_id', $failed->id)->count())->toBe(3)
        ->and(HistoryImportMessage::query()->where('history_import_conversation_id', historyRow($batch, 'ticket:1001')->id)->count())->toBe(0)
        ->and(HistoryImportFile::query()->where('data_transfer_id', $batch->id)->whereNotNull('path')->count())->toBe(3);

    $twins->last()->delete();
    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(202);

    expect(historyRow($batch, 'ticket:1002')->state)->toBe(HistoryImportRowState::Done)
        ->and($batch->refresh()->summary['outcome'])->toBe('completed')
        ->and($batch->summary['archived'])->toBe(4)
        ->and(HistoryImportFile::query()->where('data_transfer_id', $batch->id)->whereNotNull('path')->count())->toBe(0);

    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(409);
});

test('a conversation is retried at most the configured number of times', function () {
    config(['history-import.max_attempts' => 1]);
    Contact::factory()->count(2)->create(['phone_e164' => '+525500000002', 'saved_at' => null]);

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    expect($batch->refresh()->summary['retryable'])->toBe(0);
    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(409)->assertJson(['code' => 'not_resumable']);
});

test('the media disk is checked before confirming and before each run; an outage pauses the batch', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);

    config(['attachments.storage.media_disk_required' => true]);
    $summary = $this->actingAs($this->admin)->getJson(route('admin.history-import.summary', $batch))->json('summary');
    expect(collect($summary['blockers'])->pluck('code')->all())->toContain('storage_unavailable');

    config(['attachments.storage.media_disk_required' => false]);
    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);

    // The disk goes away before the worker starts.
    config(['attachments.storage.media_disk_required' => true]);
    (new ExecuteHistoryImport($batch->id))->handle(app(HistoryImportExecutor::class));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Failed)
        ->and($batch->error)->toContain('almacenamiento')
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->where('state', 'failed')->count())->toBe(0)
        ->and(Conversation::query()->imported()->count())->toBe(0);

    config(['attachments.storage.media_disk_required' => false]);
    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(202);
    (new ExecuteHistoryImport($batch->id))->handle(app(HistoryImportExecutor::class));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and(Conversation::query()->imported()->count())->toBe(4);
});

test('one unreadable ZIP entry only makes that attachment unavailable', function () {
    $this->partialMock(SafeZip::class, fn ($mock) => $mock->shouldReceive('extractFrom')->andThrow(ValidationException::withMessages(['file' => 'Un archivo del ZIP está dañado.'])));

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, historyRow($batch, 'ticket:1001')]), ['decision' => 'archive'])->assertOk();
    historyConfirm($this->admin, $batch);

    $conversation = Conversation::query()->imported()->sole();
    $codes = MessageAttachment::query()->whereIn('message_id', $conversation->messages()->select('id'))->pluck('error_code')->sort()->values()->all();

    expect(historyRow($batch, 'ticket:1001')->state)->toBe(HistoryImportRowState::Done)
        ->and($codes)->toBe(['historical_missing', 'zip_entry_invalid']);
});

test('a conversation with many files continues across runs without uploading twice or leaving orphans', function () {
    $lines = [];
    $media = [];

    for ($i = 1; $i <= 6; $i++) {
        $lines[] = "01/05/26, 10:0{$i} - Cliente Ficticio Ocho: IMG-0{$i}.jpg (archivo adjunto)";
        $media["IMG-0{$i}.jpg"] = historyPng().$i;
    }

    $lines[] = '01/05/26, 10:09 - Empresa Ficticia: Gracias';
    $batch = historyChatBatch($this->admin, implode("\n", $lines)."\n", $media);

    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);
    $batch->refresh()->forceFill(['status' => DataTransferStatus::Processing])->saveQuietly();

    // The deadline is already over: one file is copied, then the run stops.
    expect(app(HistoryImportExecutor::class)->run($batch->refresh(), $this->admin, null, microtime(true) - 1))->toBeFalse()
        ->and(Conversation::query()->imported()->count())->toBe(0)
        ->and(HistoryImportMessage::query()->whereNotNull('media_path')->count())->toBe(1);

    $firstPath = HistoryImportMessage::query()->whereNotNull('media_path')->value('media_path');

    expect(app(HistoryImportExecutor::class)->run($batch->refresh(), $this->admin))->toBeTrue();

    $paths = MessageAttachment::query()->whereNotNull('path')->pluck('path');
    expect($paths)->toHaveCount(6)
        ->and($paths)->toContain($firstPath)
        ->and(collect(Storage::disk('local')->allFiles('media'))->sort()->values()->all())->toBe($paths->sort()->values()->all());
});

test('a Chat.txt only uses the files of its own ZIP, and a name found in several ZIPs is never guessed', function () {
    $first = historyChatBatch($this->admin, historyChatText('Cliente Ficticio Siete'), ['IMG-20240101-WA0001.jpg' => historyPng().'siete']);
    historyAddFile($this->admin, $first, 'media', historyZipUpload(['Chat de WhatsApp con Otro.txt' => historyChatText('Cliente Ficticio Nueve'), 'IMG-20240101-WA0001.jpg' => historyPng().'nueve'], 'otro.zip'));
    $second = HistoryImportConversation::query()->where('data_transfer_id', $first->id)->orderByDesc('id')->firstOrFail();
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$first, $second]), ['business_participants' => ['Empresa Ficticia'], 'decision' => 'archive'])->assertOk();

    // A messages CSV row with that same name: two ZIPs have it.
    historyAddFile($this->admin, $first, 'messages', historyUpload("id,ticketId,contactNumber,fromMe,body,mediaType,mediaUrl,createdAt,connection\r\nq-1,3001,525500000077,false,,image,IMG-20240101-WA0001.jpg,2026-05-02 10:00:00,Linea Prueba\r\n", 'mensajes.csv'));
    $csvRow = historyRow($first, 'ticket:3001');
    expect(collect($csvRow->issues)->pluck('code')->all())->toContain('attachments_ambiguous')
        ->and($csvRow->attachments_available)->toBe(0);

    historyMapPlaceholders($this->admin, $first);
    historyDecide($this->admin, $first, 'archive', ['kind' => 'ticket']);
    historyConfirm($this->admin, $first);

    $hashes = MessageAttachment::query()->where('status', AttachmentStatus::Available)->get()
        ->mapWithKeys(fn (MessageAttachment $attachment): array => [Message::query()->find($attachment->message_id)->conversation->contact->name => $attachment->sha256]);

    expect($hashes['Cliente Ficticio Siete'])->toBe(hash('sha256', historyPng().'siete'))
        ->and($hashes['Cliente Ficticio Nueve'])->toBe(hash('sha256', historyPng().'nueve'))
        ->and(MessageAttachment::query()->where('error_code', 'historical_ambiguous')->count())->toBe(1);
});

test('a Chat.txt keeps the same key in another batch, so it is never imported twice', function () {
    $first = historyChatBatch($this->admin, historyChatText(), []);
    historyConfirm($this->admin, $first);

    // The same chat in a different ZIP (another fingerprint) in a later batch.
    $second = historyChatBatch($this->admin, historyChatText(), ['otro.jpg' => historyPng()]);
    $row = HistoryImportConversation::query()->where('data_transfer_id', $second->id)->sole();
    expect($row->already_imported)->toBeTrue()
        ->and($row->source_key)->toBe(HistoryImportConversation::query()->where('data_transfer_id', $first->id)->value('source_key'));

    historyConfirm($this->admin, $second);
    expect($row->refresh()->state)->toBe(HistoryImportRowState::Duplicate)
        ->and(Conversation::query()->imported()->count())->toBe(1);
});

test('a contact-keyed conversation is extended with the new messages of a later export', function () {
    $header = "id,contactNumber,contactName,fromMe,body,mediaType,mediaUrl,createdAt,connection\r\n";
    $january = "j-1,525500000088,Cliente Ficticio Ocho,false,Hola enero,chat,,2026-01-10 10:00:00,Linea Prueba\r\nj-2,525500000088,Cliente Ficticio Ocho,true,Respuesta enero,chat,,2026-01-10 10:05:00,Linea Prueba\r\n";
    $march = "j-2,525500000088,Cliente Ficticio Ocho,true,Respuesta enero,chat,,2026-01-10 10:05:00,Linea Prueba\r\nm-1,525500000088,Cliente Ficticio Ocho,false,Hola marzo,chat,,2026-03-10 10:00:00,Linea Prueba\r\n";

    $first = historyBatch($this->admin);
    historyAddFile($this->admin, $first, 'messages', historyUpload($header.$january, 'enero.csv'));
    historyMapPlaceholders($this->admin, $first);
    historyDecide($this->admin, $first, 'import');
    historyConfirm($this->admin, $first);

    $second = historyBatch($this->admin);
    historyAddFile($this->admin, $second, 'messages', historyUpload($header.$march, 'marzo.csv'));
    $row = HistoryImportConversation::query()->where('data_transfer_id', $second->id)->sole();
    expect($row->already_imported)->toBeTrue()
        ->and($row->recommendation)->toBeNull();

    historyMapPlaceholders($this->admin, $second);
    historyDecide($this->admin, $second, 'import');
    historyConfirm($this->admin, $second);

    $conversation = Conversation::query()->imported()->sole();
    expect($conversation->messages()->orderBy('created_at')->pluck('body')->all())->toBe(['Hola enero', 'Respuesta enero', 'Hola marzo'])
        ->and($conversation->last_message_at->toDateString())->toBe('2026-03-10')
        ->and($row->refresh()->state)->toBe(HistoryImportRowState::Done);

    // The same export once more adds nothing.
    $third = historyBatch($this->admin);
    historyAddFile($this->admin, $third, 'messages', historyUpload($header.$march."\r\n", 'marzo-otra-vez.csv'));
    historyMapPlaceholders($this->admin, $third);
    historyDecide($this->admin, $third, 'import');
    historyConfirm($this->admin, $third);

    expect($conversation->messages()->count())->toBe(3)
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $third->id)->sole()->state)->toBe(HistoryImportRowState::Duplicate);
});

test('nothing changes a batch after its confirmation: rebuilds, mappings, files and classification are refused', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');
    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);

    $counts = HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->pluck('message_count', 'id')->all();
    DB::table('history_import_messages')->where('data_transfer_id', $batch->id)->limit(1)->update(['history_import_conversation_id' => null]);
    app(HistoryInventory::class)->rebuild($batch);

    expect(HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->pluck('message_count', 'id')->all())->toBe($counts);

    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->firstOrFail();
    $this->actingAs($this->admin)->putJson(route('admin.history-import.mappings', $batch), ['connections' => ['Linea Prueba' => ['target' => 'placeholder']]])->assertStatus(409);
    $this->actingAs($this->admin)->deleteJson(route('admin.history-import.files.destroy', [$batch, $file]))->assertStatus(409);
    $this->actingAs($this->admin)->postJson(route('admin.history-import.rules.store', $batch), ['name' => 'x', 'criteria' => (object) [], 'decision' => 'archive'])->assertStatus(409);
});

test('a confirmation while the inventory is being rebuilt is refused', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');
    $token = $this->actingAs($this->admin)->getJson(route('admin.history-import.summary', $batch))->json('summary.confirmation_token');

    $lock = Cache::lock(HistoryInventory::lockName($batch), 60);
    $lock->get();

    $this->actingAs($this->admin)->postJson(route('admin.history-import.execute', $batch), ['confirmation_token' => $token, 'confirm' => true])
        ->assertStatus(409)->assertJson(['code' => 'batch_busy']);

    $lock->release();
    expect($batch->refresh()->status)->toBe(DataTransferStatus::Draft);
});

test('an uploaded file can be removed from a draft, taking its conversations with it', function () {
    $batch = historyStandardBatch($this->admin);
    $messages = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->where('kind', 'messages')->sole();
    $stored = $messages->path;

    $this->actingAs($this->admin)->deleteJson(route('admin.history-import.files.destroy', [$batch, $messages]))->assertOk();

    expect(HistoryImportFile::query()->whereKey($messages->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($stored))->toBeFalse()
        ->and(HistoryImportMessage::query()->where('history_import_file_id', $messages->id)->count())->toBe(0)
        // 1005 only came from the messages file; the chats report rows stay, now without messages.
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->pluck('source_key')->sort()->values()->all())
        ->toBe(['ticket:1001', 'ticket:1002', 'ticket:1003', 'ticket:1004'])
        ->and(historyRow($batch, 'ticket:1001')->message_count)->toBe(0);

    // The same file can be uploaded again.
    historyAddFile($this->admin, $batch, 'messages', historyFixtureUpload('messages.csv'));
    expect(historyRow($batch, 'ticket:1001')->message_count)->toBe(5);
});

test('a reading that dies leaves the file failed with a retry, never "reading" forever', function () {
    Queue::fake([ParseHistoryImportFile::class]);
    $batch = historyBatch($this->admin);
    historyAddFile($this->admin, $batch, 'messages', historyFixtureUpload('messages.csv'));
    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->sole();
    $file->forceFill(['status' => HistoryImportFileStatus::Parsing])->save();

    (new ParseHistoryImportFile($file->id))->failed(new RuntimeException('timeout'));
    expect($file->refresh()->status)->toBe(HistoryImportFileStatus::Failed)
        ->and($file->error)->toContain('Reintentar');

    $this->actingAs($this->admin)->postJson(route('admin.history-import.files.retry', [$batch, $file]))->assertStatus(202);
    Queue::assertPushed(ParseHistoryImportFile::class);
    (new ParseHistoryImportFile($file->id))->handle(app(HistoryFileIngestor::class));

    expect($file->refresh()->status)->toBe(HistoryImportFileStatus::Parsed)
        ->and(historyRow($batch, 'ticket:1001')->message_count)->toBe(5);
});

test('historical files above the WhatsApp send limits are kept, always typed by their content', function () {
    config(['whatsapp.media.types.image.max_bytes' => 10]);
    $batch = historyChatBatch($this->admin, historyChatText(), ['IMG-20240101-WA0001.jpg' => historyPng()]);
    historyConfirm($this->admin, $batch);

    $attachment = MessageAttachment::query()->sole();
    expect($attachment->status)->toBe(AttachmentStatus::Available)
        ->and($attachment->mime_type)->toBe('image/png');
});

test('saving a contact during an import fires no event on its live conversations', function () {
    Event::fake([ConversationChanged::class]);
    $contact = Contact::factory()->create(['phone_e164' => '+525500000001', 'saved_at' => null]);
    Conversation::factory()->create(['contact_id' => $contact->id]);

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, historyRow($batch, 'ticket:1001')]), ['decision' => 'import', 'save_contact' => true])->assertOk();
    historyConfirm($this->admin, $batch);

    expect($contact->refresh()->saved_at)->not->toBeNull();
    Event::assertNotDispatched(ConversationChanged::class);
});

test('resuming runs as whoever resumes it', function () {
    $other = User::factory()->superAdministrator()->create();
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);
    $batch->refresh()->forceFill(['status' => DataTransferStatus::Failed])->saveQuietly();

    $this->actingAs($other)->postJson(route('admin.history-import.resume', $batch))->assertStatus(202);

    expect($batch->refresh()->options['confirmed_by'])->toBe($other->id)
        ->and($batch->options['resumed_by'])->toBe($other->id);
});

test('expired batches keep no names, numbers or error texts in any state, and source keys keep only identifiers', function () {
    Contact::factory()->count(2)->create(['phone_e164' => '+525500000002', 'saved_at' => null]);
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    $draft = historyBatch($this->admin);
    historyAddFile($this->admin, $draft, 'messages', historyUpload((string) file_get_contents(historyFixture('messages.csv'))."\r\n", 'otra-copia.csv'));
    $draft->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();
    $batch->refresh()->forceFill(['expires_at' => now()->subDay()])->saveQuietly();

    expect(app(PruneHistoryImports::class)->handle())->toBe(2);

    $leftovers = HistoryImportConversation::query()->whereIn('data_transfer_id', [$batch->id, $draft->id])
        ->where(fn ($query) => $query->whereNotNull('contact_name')->orWhereNotNull('contact_phone')->orWhereNotNull('error')->orWhereNotNull('source_details'))
        ->count();
    $details = HistoryImportSourceKey::query()->whereNotNull('details')->pluck('details')->toJson();

    expect($leftovers)->toBe(0)
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $draft->id)->count())->toBe(0)
        ->and($details)->not->toContain('5500000')
        ->and($details)->not->toContain('Cliente Ficticio')
        ->and(HistoryImportFile::query()->where('data_transfer_id', $batch->id)->pluck('original_name')->unique()->all())->toBe(['archivo']);
});
