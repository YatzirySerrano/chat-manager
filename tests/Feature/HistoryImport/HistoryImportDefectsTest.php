<?php

use App\Actions\Departments\SummarizeDepartmentConversations;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\DataTransferStatus;
use App\Jobs\ExecuteHistoryImport;
use App\Jobs\ParseHistoryImportFile;
use App\Jobs\RebuildHistoryImportInventory;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\User;
use App\Services\Migration\History\HistoryImportExecutor;
use App\Services\Migration\History\HistoryValues;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Defects of the audit at 74ce91c (final phase). Every file here is
| SYNTHETIC (fake names and numbers): SQL dump dates and null markers, a
| CSV that is ASCII for its first 64 KB and Windows-1252 afterwards, the
| lock ownership of the execution job, mappings whose target is deleted
| after mapping, job timeouts below the exports worker and the department
| summary that must not count imported history.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
});

test('SQL dump timestamps with offsets are read, with or without fraction, T or space', function (string $value, string $utc) {
    expect(app(HistoryValues::class)->date($value, 'America/Mexico_City')?->format('Y-m-d H:i:s'))->toBe($utc);
})->with([
    'postgres timestamptz +hh' => ['2023-05-01 15:34:56.789+00', '2023-05-01 15:34:56'],
    'space and +hh:mm' => ['2023-05-01 15:34:56+00:00', '2023-05-01 15:34:56'],
    'space, fraction and -hh:mm (Sao Paulo)' => ['2023-05-01 12:34:56.123456-03:00', '2023-05-01 15:34:56'],
    'T and +hhmm' => ['2023-05-01T17:34:56+0200', '2023-05-01 15:34:56'],
    'T, fraction and Z' => ['2023-05-01T15:34:56.5Z', '2023-05-01 15:34:56'],
    'space and z without seconds' => ['2023-05-01 15:34z', '2023-05-01 15:34:00'],
]);

test('a date with an offset ignores the batch time zone; one without it uses it', function () {
    $values = app(HistoryValues::class);

    expect($values->date('2023-05-01 15:34:56.789+00', 'America/Sao_Paulo')?->getTimestamp())
        ->toBe($values->date('2023-05-01 15:34:56.789+00', 'America/Mexico_City')?->getTimestamp())
        ->and($values->date('2023-05-01 12:00:00.000000', 'America/Sao_Paulo')?->format('H:i'))->toBe('15:00')
        ->and($values->date('2023-05-01 12:00:00', 'America/Mexico_City')?->format('H:i'))->toBe('18:00')
        ->and($values->date('2023-02-30 10:00:00+00', 'UTC'))->toBeNull();
});

test('a synthetic PostgreSQL dump CSV with \\N and NULL markers is read as nulls outside the text', function () {
    $csv = "id,body,ack,read,fromMe,mediaUrl,mediaType,ticketId,quotedMsgId,isDeleted,createdAt,contactNumber\n"
        ."p-1,Hola (ficticio),0,t,f,\\N,chat,501,\\N,f,2023-05-01 15:34:56.789+00,5215500000071\n"
        ."p-2,Respuesta,1,t,t,NULL,chat,501,NULL,NULL,2023-05-01 15:35:10+00,5215500000071\n"
        ."p-3,NULL,1,t,t,\\N,chat,501,\\N,f,2023-05-01 15:36:00+00:00,5215500000071\n";

    $batch = historyBatch($this->admin);
    historyAddFile($this->admin, $batch, 'messages', historyUpload($csv, 'messages-postgres-sintetico.csv'));

    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->firstOrFail();
    $staged = HistoryImportMessage::query()->where('history_import_file_id', $file->id)->orderBy('line')->get();

    expect($file->rows_accepted)->toBe(3)
        ->and($file->issues['invalid_date'] ?? null)->toBeNull()
        ->and($staged->pluck('media_name')->all())->toBe([null, null, null])
        ->and($staged->pluck('quoted_source_id')->all())->toBe([null, null, null])
        ->and($staged->pluck('type')->unique()->all())->toBe(['text'])
        // The text is kept as written: a customer may really have sent "NULL".
        ->and($staged[2]->body)->toBe('NULL')
        ->and($staged[0]->occurred_at?->utc()->format('Y-m-d H:i:s'))->toBe('2023-05-01 15:34:56');
});

test('a CSV that is plain ASCII for its first 64 KB and Windows-1252 afterwards is stored as valid UTF-8', function () {
    $csv = "id,ticketId,contactNumber,fromMe,body,createdAt\n";

    for ($line = 1; strlen($csv) < 70000; $line++) {
        $csv .= "a-{$line},601,5215500000072,false,Mensaje ficticio numero {$line},2026-03-01 10:00:00\n";
    }

    $csv .= 'a-final,601,5215500000072,false,"'.mb_convert_encoding('Atención José, año', 'Windows-1252', 'UTF-8').'",2026-03-01 11:00:00'."\n";

    $batch = historyBatch($this->admin);
    historyAddFile($this->admin, $batch, 'messages', historyUpload($csv, 'mensajes-mixtos-sintetico.csv'));

    $file = HistoryImportFile::query()->where('data_transfer_id', $batch->id)->firstOrFail();
    expect($file->error)->toBeNull();
    $last = HistoryImportMessage::query()->where('source_message_id', 'a-final')->firstOrFail();

    expect($last->body)->toBe('Atención José, año')
        ->and(mb_check_encoding((string) $last->body, 'UTF-8'))->toBeTrue();
});

test('the failed() of a stale run neither releases the live run\'s lock nor fails its batch', function () {
    $batch = historyStandardBatch($this->admin);
    $stale = new ExecuteHistoryImport($batch->id);
    $live = new ExecuteHistoryImport($batch->id);

    $batch->forceFill(['status' => DataTransferStatus::Processing, 'options' => [...$batch->options, 'run_token' => $live->runToken]])->saveQuietly();
    $liveLock = Cache::lock(ExecuteHistoryImport::lockName($batch->id), 300, $live->runToken);
    expect($liveLock->get())->toBeTrue();

    $stale->failed(new RuntimeException('retry_after reached'));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Processing)
        ->and(Cache::lock(ExecuteHistoryImport::lockName($batch->id))->isLocked())->toBeTrue();

    // The live run itself failing (killed by its timeout) frees its lock and leaves the batch resumable.
    $live->failed(new RuntimeException('timeout'));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Failed)
        ->and(Cache::lock(ExecuteHistoryImport::lockName($batch->id))->isLocked())->toBeFalse();

    $liveLock->forceRelease();
});

test('a mapping whose target is deleted after mapping blocks the confirmation and is never attached', function () {
    $department = Department::factory()->create();
    $batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $batch);
    $this->actingAs($this->admin)->putJson(route('admin.history-import.mappings', $batch), ['departments' => ['Ventas' => $department->id]])->assertOk();
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);

    // Departments are deleted logically (inactive).
    $department->forceFill(['is_active' => false])->save();

    $summary = $this->actingAs($this->admin)->getJson(route('admin.history-import.summary', $batch))->assertOk()->json('summary');

    expect($summary['ready'])->toBeFalse()
        ->and(collect($summary['blockers'])->pluck('code')->all())->toContain('stale_mappings');

    $this->actingAs($this->admin)->postJson(route('admin.history-import.execute', $batch), [
        'confirmation_token' => $summary['confirmation_token'],
        'confirm' => true,
    ])->assertStatus(422);

    // Deleted between the confirmation and the run: the run never attaches it.
    $other = Department::factory()->create();
    $this->actingAs($this->admin)->putJson(route('admin.history-import.mappings', $batch), ['departments' => ['Ventas' => $other->id]])->assertOk();
    Queue::fake([ExecuteHistoryImport::class]);
    historyConfirm($this->admin, $batch);
    $other->forceFill(['is_active' => false])->save();
    (new ExecuteHistoryImport($batch->id))->handle(app(HistoryImportExecutor::class));

    expect($batch->refresh()->status)->toBe(DataTransferStatus::Completed)
        ->and(Conversation::query()->imported()->whereNotNull('department_id')->count())->toBe(0)
        ->and(Conversation::query()->imported()->count())->toBeGreaterThan(0);
});

test('the reading, inventory and execution jobs stop before the exports worker kills them', function () {
    config(['history-import.job_timeout' => 600, 'queue.connections.'.config('queue.default').'.retry_after' => 900]);

    expect(ParseHistoryImportFile::timeout())->toBe(600)
        ->and((new ParseHistoryImportFile(1))->timeout)->toBeLessThan(660)
        ->and((new RebuildHistoryImportInventory(1))->timeout)->toBeLessThan(660)
        ->and((new ExecuteHistoryImport(1))->timeout)->toBeLessThan(660);

    // Never above retry_after - 30, whatever is configured.
    config(['history-import.job_timeout' => 5000]);
    expect(ParseHistoryImportFile::timeout())->toBe(870);
});

test('imported and archived history never counts in the department summary', function () {
    $department = Department::factory()->create();
    Conversation::factory()->create(['department_id' => $department->id, 'status' => ConversationStatus::Pending]);

    $before = app(SummarizeDepartmentConversations::class)->handle($department);

    Conversation::factory()->create(['department_id' => $department->id, 'status' => ConversationStatus::Closed])
        ->forceFill(['origin' => ConversationOrigin::Whaticket, 'closed_at' => now()])->save();
    Conversation::factory()->create(['department_id' => $department->id, 'status' => ConversationStatus::Closed])
        ->forceFill(['origin' => ConversationOrigin::Whaticket, 'history_only_at' => now(), 'closed_at' => now()])->save();

    expect(app(SummarizeDepartmentConversations::class)->handle($department))->toBe($before)
        ->and($before['total'])->toBe(1);
});
