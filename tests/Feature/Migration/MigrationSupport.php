<?php

use App\Enums\DataTransferType;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Helpers shared by the migration tests (names differ from the importer
 * tests' helpers, which live in the same process).
 */
function migrationCsv(string $content, string $name = 'contactos.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'migration');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function migrationUpload(User $user, UploadedFile $file, string $source = 'generic'): DataTransfer
{
    test()->actingAs($user)->post(route('contacts.import.store'), ['file' => $file, 'source' => $source])->assertSessionHasNoErrors();

    return DataTransfer::query()->where('type', DataTransferType::Import)->latest('id')->firstOrFail();
}

function migrationRun(User $user, string $content, string $decision = 'skip', string $source = 'generic'): DataTransfer
{
    $transfer = migrationUpload($user, migrationCsv($content), $source);

    test()->actingAs($user)->put(route('contacts.import.update', $transfer), [
        'mapping' => $transfer->options['mapping'],
        'decision' => $decision,
    ])->assertSessionHasNoErrors();

    test()->actingAs($user)->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    return $transfer->refresh();
}
