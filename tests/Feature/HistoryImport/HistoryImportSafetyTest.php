<?php

use App\Enums\AttachmentStatus;
use App\Models\Conversation;
use App\Models\HistoryImportFile;
use App\Models\HistoryImportMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Migration\History\HistoryImportReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Defensive reading of what is uploaded: ZIP slip, symbolic links, zip
| bombs, content that does not match its extension, files whose content
| is not what their name says, formula injection on the report, and the
| source files (and Whaticket) never touched.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
    $this->batch = historyBatch($this->admin);
    $this->upload = fn (string $kind, $file) => $this->actingAs($this->admin)->post(route('admin.history-import.files.store', $this->batch), ['kind' => $kind, 'file' => $file]);
});

test('a ZIP with path traversal or absolute paths is refused before anything is read', function (string $entry) {
    ($this->upload)('media', historyZipUpload([$entry => historyPng()]))->assertSessionHasErrors('file');

    expect(HistoryImportFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'parent directory' => ['../../outside.jpg'],
    'nested traversal' => ['media/../../outside.jpg'],
    'absolute path' => ['/etc/outside.jpg'],
]);

test('a ZIP with a symbolic link is refused', function () {
    $file = historyZipUpload(['link.jpg' => '/etc/passwd'], tamper: function (ZipArchive $zip): void {
        $zip->setExternalAttributesName('link.jpg', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    });

    ($this->upload)('media', $file)->assertSessionHasErrors(['file' => 'El ZIP contiene accesos directos (enlaces simbólicos) y no puede importarse.']);
});

test('a zip bomb is refused by its compression ratio and by its declared size', function () {
    ($this->upload)('media', historyZipUpload(['bomb.jpg' => str_repeat("\0", 5 * 1024 * 1024)]))
        ->assertSessionHasErrors(['file' => 'El ZIP tiene un contenido comprimido sospechoso y no puede importarse.']);

    config(['history-import.zip.max_uncompressed_bytes' => 1024]);
    ($this->upload)('media', historyZipUpload(['a.jpg' => random_bytes(800), 'b.jpg' => random_bytes(800)]))
        ->assertSessionHasErrors('file');

    config(['history-import.zip.max_entries' => 2]);
    ($this->upload)('media', historyZipUpload(['a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c']))
        ->assertSessionHasErrors('file');

    expect(HistoryImportFile::query()->count())->toBe(0);
});

test('a file whose content does not match its extension or kind is refused', function () {
    ($this->upload)('messages', historyZipUpload(['x.txt' => 'x'], 'mensajes.csv'))->assertSessionHasErrors('file');
    ($this->upload)('messages', historyUpload("\x00\x01binario", 'mensajes.csv'))->assertSessionHasErrors('file');
    ($this->upload)('chats', historyUpload('contactName,contactNumber', 'reporte.zip'))->assertSessionHasErrors('file');
    ($this->upload)('messages', historyUpload('a', 'mensajes.exe'))->assertSessionHasErrors('file');
    ($this->upload)('nada', historyFixtureUpload('messages.csv'))->assertSessionHasErrors('kind');

    expect(HistoryImportFile::query()->count())->toBe(0);
});

test('the same file is never added twice', function () {
    ($this->upload)('messages', historyFixtureUpload('messages.csv'))->assertSessionHasNoErrors();
    ($this->upload)('messages', historyFixtureUpload('messages.csv', 'copia.csv'))->assertSessionHasErrors(['file' => 'Este archivo ya está en esta importación.']);

    $other = historyBatch($this->admin);
    $this->actingAs($this->admin)->post(route('admin.history-import.files.store', $other), ['kind' => 'messages', 'file' => historyFixtureUpload('messages.csv')])
        ->assertSessionHasErrors('file');
});

test('a file whose content is not what its name says is never stored as that type', function () {
    $csv = "id,ticketId,contactNumber,fromMe,body,mediaType,mediaUrl,createdAt,connection\r\n"
        ."x-1,2001,525500000009,false,Foto ficticia,image,IMG-0666.jpg,2026-03-05 10:00:00,Linea Prueba\r\n"
        ."x-2,2001,525500000009,false,Página ficticia,document,pagina.pdf,2026-03-05 10:01:00,Linea Prueba\r\n";

    ($this->upload)('messages', historyUpload($csv, 'mensajes.csv'))->assertSessionHasNoErrors();
    ($this->upload)('media', historyZipUpload([
        'IMG-0666.jpg' => "<?php echo 'no'; ?>",
        'pagina.pdf' => '<html><script>alert(1)</script></html>',
    ]))->assertSessionHasNoErrors();

    historyMapPlaceholders($this->admin, $this->batch);
    historyDecide($this->admin, $this->batch, 'archive');
    historyConfirm($this->admin, $this->batch);

    $attachments = MessageAttachment::query()->whereIn('message_id', Message::query()->select('id')->whereIn('conversation_id', Conversation::query()->imported()->select('id')))->get();

    expect($attachments)->toHaveCount(2)
        ->and($attachments->pluck('status')->unique()->all())->toBe([AttachmentStatus::Unavailable])
        ->and($attachments->pluck('error_code')->unique()->all())->toBe(['type_not_allowed'])
        ->and($attachments->pluck('path')->filter()->all())->toBe([]);

    $conversation = Conversation::query()->imported()->sole();
    $this->actingAs($this->admin)->get(route('admin.history.conversations.attachments.show', [$conversation, $attachments->first()]))->assertNotFound();
});

test('cells that look like formulas are neutralized in the report', function () {
    ($this->upload)('chats', historyFixtureUpload('chats-report.csv'))->assertSessionHasNoErrors();

    $report = $this->actingAs($this->admin)->get(route('admin.history-import.report', $this->batch))->assertOk();
    $content = $report->streamedContent();

    expect($content)->toContain("'=HYPERLINK(")
        ->and($content)->not->toContain(',=HYPERLINK(')
        ->and(HistoryImportReport::cell('+52 55'))->toBe("'+52 55")
        ->and(HistoryImportReport::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(HistoryImportReport::cell('Texto normal'))->toBe('Texto normal')
        ->and(HistoryImportReport::cell('  =1+1'))->toBe("'  =1+1")
        ->and(HistoryImportReport::cell("\t-2"))->toBe("'\t-2")
        ->and(HistoryImportReport::cell('＝HYPERLINK("x")'))->toBe("'＝HYPERLINK(\"x\")")
        ->and(HistoryImportReport::cell('＋52'))->toBe("'＋52");
});

test('the source files are never changed and nothing reaches Whaticket or any other service', function () {
    $fixtures = ['chats-report.csv', 'messages.csv', 'contacts.csv'];
    $before = array_map(fn (string $name): string => hash_file('sha256', historyFixture($name)), $fixtures);
    Http::fake();

    $batch = historyStandardBatch($this->admin);
    historyAddFile($this->admin, $batch, 'contacts', historyFixtureUpload('contacts.csv'));
    historyMapPlaceholders($this->admin, $batch);
    historyDecide($this->admin, $batch, 'archive');
    historyDecide($this->admin, $batch, 'exclude', ['empty' => true]);
    historyConfirm($this->admin, $batch);

    expect(array_map(fn (string $name): string => hash_file('sha256', historyFixture($name)), $fixtures))->toBe($before);
    Http::assertNothingSent();
});

test('a CSV record without its closing quote never reads the whole file at once', function () {
    config(['history-import.max_record_bytes' => 4096]);
    $csv = "id,ticketId,contactNumber,fromMe,body,mediaType,mediaUrl,createdAt,connection\r\n"
        .'z-1,4001,525500000099,false,"sin cierre '.str_repeat('x', 20000)."\r\n";

    ($this->upload)('messages', historyUpload($csv, 'mensajes.csv'))->assertSessionHasNoErrors();

    $file = HistoryImportFile::query()->where('data_transfer_id', $this->batch->id)->sole();
    expect($file->status->value)->toBeIn(['parsed', 'failed'])
        ->and(HistoryImportMessage::query()->where('history_import_file_id', $file->id)->get()->every(fn ($row) => mb_strlen((string) $row->body) <= 10000))->toBeTrue();
});

test('dates outside what a history can hold are rejected and repeated messages are counted once', function () {
    $csv = "id,ticketId,contactNumber,fromMe,body,mediaType,mediaUrl,createdAt,connection\r\n"
        ."d-1,5001,525500000098,false,Antiguo,chat,,1900-01-01 10:00:00,Linea Prueba\r\n"
        ."d-2,5001,525500000098,false,Bien,chat,,2026-01-01 10:00:00,Linea Prueba\r\n"
        ."d-2,5001,525500000098,false,Bien repetido,chat,,2026-01-01 10:00:00,Linea Prueba\r\n";

    ($this->upload)('messages', historyUpload($csv, 'mensajes.csv'))->assertSessionHasNoErrors();
    $file = HistoryImportFile::query()->where('data_transfer_id', $this->batch->id)->sole();

    expect($file->rows_total)->toBe(3)
        ->and($file->rows_accepted)->toBe(1)
        ->and($file->issues['invalid_date']['count'])->toBe(1)
        ->and($file->issues['duplicate_message']['count'])->toBe(1);
});

test('an xlsx chats report is streamed and its formulas are read as text', function () {
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([['contactName', 'contactNumber', 'conversationId', 'createdAt'], ['Cliente Ficticio X', '525500000055', '6001', '2026-02-01 10:00:00']]);
    $sheet->setCellValue('A3', '=HYPERLINK("http://example.invalid","x")');
    $sheet->setCellValueExplicit('B3', '525500000056', DataType::TYPE_STRING);
    $sheet->setCellValue('C3', 6002);
    $path = tempnam(sys_get_temp_dir(), 'histxlsx').'.xlsx';
    (new Xlsx($book))->save($path);

    ($this->upload)('chats', new UploadedFile($path, 'reporte.xlsx', null, null, true))->assertSessionHasNoErrors();

    expect(historyRow($this->batch, 'ticket:6001')->contact_name)->toBe('Cliente Ficticio X')
        ->and(historyRow($this->batch, 'ticket:6002')->contact_name)->toBe('=HYPERLINK("http://example.invalid","x")')
        ->and(historyRow($this->batch, 'ticket:6002')->contact_phone)->toBe('+525500000056');
});

test('conversations keep no foreign key to the batch (no table copy on MariaDB)', function () {
    $foreignKeys = collect(Schema::getForeignKeys('conversations'))->pluck('columns')->flatten()->all();
    $indexes = collect(Schema::getIndexes('conversations'))->pluck('name')->all();

    expect($foreignKeys)->not->toContain('imported_by_transfer_id')
        ->and($indexes)->toContain('conversations_imported_by_transfer_index', 'conversations_origin_history_index');
});
