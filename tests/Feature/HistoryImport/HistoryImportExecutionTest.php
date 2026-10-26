<?php

use App\Enums\DataTransferStatus;
use App\Enums\HistoryImportRowState;
use App\Jobs\ExecuteHistoryImport;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\HistoryImportSourceKey;
use App\Models\Message;
use App\Models\User;
use App\Services\Migration\History\HistoryImportExecutor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Execution: idempotent (source keys), resumable after an interruption,
| per-conversation failures isolated, duplicates of earlier batches
| skipped, contacts never merged by name and kept out of the directory
| unless the admin chose to save them, and thousands of messages processed
| with bounded memory.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
});

test('an interrupted batch resumes where it stopped without duplicating anything', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'import');
    historyDecide($this->admin, $batch, 'archive', ['group' => true]);
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);

    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);
    Queue::assertPushed(ExecuteHistoryImport::class);
    $batch->refresh()->forceFill(['status' => DataTransferStatus::Processing])->saveQuietly();

    // The worker stops after one conversation (a crash in the middle of the
    // next one would roll it back: it stays pending).
    expect(app(HistoryImportExecutor::class)->run($batch->refresh(), $this->admin, 1))->toBeFalse()
        ->and(Conversation::query()->imported()->count())->toBe(1)
        ->and($batch->refresh()->rows_processed)->toBe(1);

    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(202);
    (new ExecuteHistoryImport($batch->id))->handle(app(HistoryImportExecutor::class));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and(Conversation::query()->imported()->count())->toBe(4)
        ->and(HistoryImportSourceKey::query()->where('source_kind', 'conversation')->count())->toBe(4)
        ->and(Message::query()->whereIn('conversation_id', Conversation::query()->imported()->select('id'))->count())->toBe(12);

    $this->actingAs($this->admin)->postJson(route('admin.history-import.resume', $batch))->assertStatus(409)->assertJson(['code' => 'not_resumable']);
});

test('a conversation that cannot be created is reported and the rest of the batch goes on', function () {
    // Two contacts outside the directory with the same number: the importer never guesses.
    Contact::factory()->count(2)->create(['phone_e164' => '+525500000002', 'saved_at' => null]);

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    $failed = historyRow($batch, 'ticket:1002');
    expect($failed->state)->toBe(HistoryImportRowState::Failed)
        ->and($failed->error)->toContain('varios contactos')
        ->and($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($batch->summary['failed'])->toBe(1)
        ->and($batch->summary['archived'])->toBe(3)
        ->and($batch->rows_failed)->toBe(1);
});

test('conversations already imported by an earlier batch are skipped as duplicates', function () {
    $first = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $first);
    historyDecide($this->admin, $first, 'exclude');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$first, historyRow($first, 'ticket:1001')]), ['decision' => 'import'])->assertOk();
    historyConfirm($this->admin, $first);

    // A later export of the same period (another file, same tickets).
    $second = historyBatch($this->admin);
    $content = (string) file_get_contents(historyFixture('messages.csv'))."\r\n";
    historyAddFile($this->admin, $second, 'messages', historyUpload($content, 'mensajes-otra-vez.csv'));

    $row = historyRow($second, 'ticket:1001');
    expect($row->already_imported)->toBeTrue()
        ->and($row->recommendation)->toBe('exclude')
        ->and(collect($row->issues)->pluck('code')->all())->toContain('already_imported');

    historyMapPlaceholders($this->admin, $second);
    historyDecide($this->admin, $second, 'archive');
    $summary = historyConfirm($this->admin, $second);

    expect($summary['duplicates'])->toBe(1)
        ->and(historyRow($second, 'ticket:1001')->state)->toBe(HistoryImportRowState::Duplicate)
        ->and($second->refresh()->summary['duplicates'])->toBe(1)
        ->and(Conversation::query()->imported()->whereHas('contact', fn ($query) => $query->where('phone_e164', '+525500000001'))->count())->toBe(1);
});

test('contacts are matched by normalized number, never by name, and stay out of the directory unless chosen', function () {
    // Same number written with the old "521" prefix in Whaticket; different name.
    $existing = Contact::factory()->create(['name' => 'Nombre En Chat Manager', 'phone_e164' => '+525500000001']);
    $sameName = Contact::factory()->create(['name' => 'Cliente Ficticio Dos', 'phone_e164' => '+525599999999']);

    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'import');
    historyDecide($this->admin, $batch, 'archive', ['group' => true]);
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, historyRow($batch, 'ticket:1004')]), ['save_contact' => true])->assertOk();
    $before = Contact::query()->count();
    historyConfirm($this->admin, $batch);

    $byKey = fn (string $key) => Conversation::query()->findOrFail(historyRow($batch, $key)->result_conversation_id)->contact;

    expect($byKey('ticket:1001')->id)->toBe($existing->id)
        ->and($existing->refresh()->name)->toBe('Nombre En Chat Manager')
        ->and($byKey('ticket:1002')->id)->not->toBe($sameName->id)
        ->and($byKey('ticket:1002')->saved_at)->toBeNull()
        ->and($byKey('ticket:1004')->saved_at)->not->toBeNull()
        // 1002, 1004 and the group are new; the empty 1003 (excluded) creates nothing.
        ->and(Contact::query()->count() - $before)->toBe(3)
        ->and(Contact::query()->where('phone_e164', '+525500000003')->exists())->toBeFalse()
        ->and(Contact::query()->savedInDirectory()->count())->toBe(3);
});

test('thousands of messages are processed with bounded memory and time', function () {
    $lines = ['id,ticketId,contactNumber,contactName,fromMe,body,mediaType,mediaUrl,createdAt,connection'];
    $start = strtotime('2026-03-01 08:00:00');

    for ($i = 0; $i < 5000; $i++) {
        $ticket = 5000 + intdiv($i, 50);
        $phone = '5255'.str_pad((string) ($ticket % 100000000), 8, '0', STR_PAD_LEFT);
        $lines[] = implode(',', ["p-{$i}", (string) $ticket, $phone, 'Cliente Ficticio '.$ticket, $i % 2 === 0 ? 'false' : 'true', "Mensaje ficticio {$i}", 'chat', '', date('Y-m-d H:i:s', $start + $i * 60), 'Linea Prueba']);
    }

    $batch = historyBatch($this->admin);
    $memory = memory_get_usage(true);
    $began = microtime(true);

    historyAddFile($this->admin, $batch, 'messages', historyUpload(implode("\r\n", $lines)."\r\n", 'mensajes-grande.csv'));
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'import');
    historyConfirm($this->admin, $batch);

    $elapsed = microtime(true) - $began;

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($batch->summary['imported'])->toBe(100)
        ->and($batch->summary['messages'])->toBe(5000)
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->where('state', 'done')->count())->toBe(100)
        ->and($elapsed)->toBeLessThan(120.0)
        ->and(memory_get_peak_usage(true) - $memory)->toBeLessThan(192 * 1024 * 1024);

    $first = Conversation::query()->where('imported_by_transfer_id', $batch->id)->orderBy('id')->firstOrFail();
    $times = Message::query()->where('conversation_id', $first->id)->orderBy('id')->pluck('created_at')->map->getTimestamp()->all();
    $sorted = $times;
    sort($sorted);
    expect($times)->toBe($sorted);
});

test('a confirmed batch is executed by the queued job with the confirming admin', function () {
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'exclude');

    historyConfirm($this->admin, $batch);

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and($batch->options['confirmed_by'])->toBe($this->admin->id)
        ->and($batch->summary['excluded'])->toBe(5)
        ->and(Conversation::query()->imported()->count())->toBe(0)
        ->and(DataTransfer::query()->whereKey($batch->id)->value('started_at'))->not->toBeNull();
});
