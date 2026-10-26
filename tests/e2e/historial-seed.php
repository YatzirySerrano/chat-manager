<?php

/*
| Data of the Whaticket history importer scenario (tests/e2e/historial.mjs),
| on top of tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php:
| the superadministrator e2e-admin and the agents Ana, Beto and Carla).
|
| Every file is SYNTHETIC: the fixtures of tests/fixtures/whaticket (fake
| names "Cliente Ficticio", fake numbers +52 55 0000 000X, example.invalid
| links) and a Whaticket-medias.zip built here with a 1x1 PNG named like the
| image the messages reference (the audio is missing on purpose). Nothing
| comes from a real export, a real phone number or a real conversation.
|
| It only touches the testing database (chatmanager_testing) and refuses any
| other one; the ZIP is written to the directory given (the scenario's
| screenshots folder or a temporary one), never to public/.
|
|   php tests/e2e/historial-seed.php files <dir>
|       -> {"chats", "messages", "contacts", "media", "chat_txt"} (paths)
|   php tests/e2e/historial-seed.php imported <transfer id>
|       -> {"archived": {"id", "contact"}, "imported": {"id", "contact"}}
*/

use App\Models\Conversation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    TestDatabase::guard(DB::connection()->getDatabaseName());
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$fixtures = dirname(__DIR__).'/fixtures/whaticket';
$command = $argv[1] ?? 'files';

if ($command === 'files') {
    $directory = $argv[2] ?? sys_get_temp_dir();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $zipPath = rtrim($directory, '/').'/Whaticket-medias.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('IMG-0001.jpg', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $zip->close();

    // The Chat.txt keeps its exported name (WhatsApp calls it "Chat.txt").
    $chatPath = rtrim($directory, '/').'/Chat.txt';
    copy($fixtures.'/chat-android.txt', $chatPath);

    echo json_encode([
        'chats' => $fixtures.'/chats-report.csv',
        'messages' => $fixtures.'/messages.csv',
        'contacts' => $fixtures.'/contacts.csv',
        'media' => $zipPath,
        'chat_txt' => $chatPath,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

    exit(0);
}

if ($command === 'imported') {
    $transfer = (int) ($argv[2] ?? 0);
    $present = function (?Conversation $conversation): ?array {
        return $conversation === null ? null : [
            'id' => $conversation->id,
            'contact' => $conversation->contact?->name,
            'status' => $conversation->status->value,
        ];
    };
    $base = fn () => Conversation::query()->where('imported_by_transfer_id', $transfer)->with('contact:id,name')->orderBy('id');

    echo json_encode([
        'archived' => $present($base()->whereNotNull('history_only_at')->first()),
        'imported' => $present($base()->whereNull('history_only_at')->first()),
        'total' => $base()->count(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;

    exit(0);
}

fwrite(STDERR, "Unknown command [{$command}].".PHP_EOL);
exit(1);
