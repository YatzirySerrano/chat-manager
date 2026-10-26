<?php

use App\Enums\DataTransferType;
use App\Models\DataTransfer;
use App\Models\HistoryImportConversation;
use App\Models\User;
use App\Services\Migration\History\HistoryImportBatches;
use Illuminate\Http\UploadedFile;

/*
| Helpers of the Whaticket history importer tests. Every fixture under
| tests/fixtures/whaticket is SYNTHETIC: fake names ("Cliente Ficticio"),
| fake numbers (+52 55 0000 000X) and example.invalid links, built from the
| documented shapes of the exports (the messages CSV and Chat.txt formats
| are not officially documented). Names differ from other tests' helpers,
| which share the same process.
*/

/** A 1x1 PNG (synthetic image). */
const HISTORY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function historyFixture(string $name): string
{
    return dirname(__DIR__, 2).'/fixtures/whaticket/'.$name;
}

/**
 * An upload of a copy of the content (the fixture itself is never moved).
 */
function historyUpload(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'histtest');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function historyFixtureUpload(string $fixture, ?string $name = null): UploadedFile
{
    return historyUpload((string) file_get_contents(historyFixture($fixture)), $name ?? $fixture);
}

/**
 * A ZIP with the given entries (name => content).
 *
 * @param  array<string, string>  $entries
 */
function historyZip(array $entries, ?callable $tamper = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'histzip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    if ($tamper !== null) {
        $tamper($zip);
    }

    $zip->close();

    return $path;
}

function historyZipUpload(array $entries, string $name = 'Whaticket-medias.zip', ?callable $tamper = null): UploadedFile
{
    return new UploadedFile(historyZip($entries, $tamper), $name, null, null, true);
}

function historyPng(): string
{
    return (string) base64_decode(HISTORY_PNG);
}

/**
 * A new batch created through the admin route.
 */
function historyBatch(User $actor): DataTransfer
{
    test()->actingAs($actor)->post(route('admin.history-import.store'), ['source_instance' => 'whaticket-prueba', 'timezone' => 'America/Mexico_City'])->assertSessionHasNoErrors();

    return DataTransfer::query()->where('type', DataTransferType::HistoryImport)->latest('id')->firstOrFail();
}

function historyAddFile(User $actor, DataTransfer $batch, string $kind, UploadedFile $file): void
{
    test()->actingAs($actor)
        ->post(route('admin.history-import.files.store', $batch), ['kind' => $kind, 'file' => $file])
        ->assertSessionHasNoErrors();
}

/**
 * The standard batch: chats report + messages CSV + media ZIP (with the
 * image the messages reference; the audio is missing on purpose).
 */
function historyStandardBatch(User $actor): DataTransfer
{
    $batch = historyBatch($actor);
    historyAddFile($actor, $batch, 'chats', historyFixtureUpload('chats-report.csv'));
    historyAddFile($actor, $batch, 'messages', historyFixtureUpload('messages.csv'));
    historyAddFile($actor, $batch, 'media', historyZipUpload(['IMG-0001.jpg' => historyPng()]));

    return $batch->refresh();
}

/**
 * Every source connection mapped to a historical placeholder connection.
 */
function historyMapPlaceholders(User $actor, DataTransfer $batch): void
{
    $connections = [];

    foreach (app(HistoryImportBatches::class)->mappingRows($batch)['connections'] as $row) {
        $connections[$row['source']] = ['target' => 'placeholder'];
    }

    test()->actingAs($actor)->putJson(route('admin.history-import.mappings', $batch), ['connections' => $connections])->assertOk();
}

/**
 * Decide the inventory rows matching the filters.
 *
 * @param  array<string, mixed>  $filters
 */
function historyDecide(User $actor, DataTransfer $batch, string $decision, array $filters = []): array
{
    return test()->actingAs($actor)
        ->postJson(route('admin.history-import.inventory.bulk', $batch), ['decision' => $decision, 'filters' => (object) $filters])
        ->assertOk()
        ->json('result');
}

/**
 * Review the summary and confirm it (the job runs synchronously in tests).
 */
function historyConfirm(User $actor, DataTransfer $batch): array
{
    $summary = test()->actingAs($actor)->getJson(route('admin.history-import.summary', $batch))->assertOk()->json('summary');

    test()->actingAs($actor)->postJson(route('admin.history-import.execute', $batch), [
        'confirmation_token' => $summary['confirmation_token'],
        'confirm' => true,
    ])->assertStatus(202);

    return $summary;
}

/**
 * Inventory row id of a source key.
 */
function historyRow(DataTransfer $batch, string $sourceKey): HistoryImportConversation
{
    return HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->where('source_key', $sourceKey)->firstOrFail();
}
