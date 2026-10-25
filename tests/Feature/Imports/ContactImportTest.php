<?php

use App\Enums\AuditAction;
use App\Enums\ContactSource;
use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Jobs\ImportContacts;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * An .xlsx upload whose first sheet holds the rows. Values starting with "="
 * are written as real formulas, as a spreadsheet application would.
 *
 * @param  list<list<string>>  $rows
 */
function importWorkbook(array $rows, string $name = 'contactos.xlsx', ?callable $tamper = null): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();

    foreach ($rows as $rowIndex => $cells) {
        foreach ($cells as $columnIndex => $value) {
            $coordinate = chr(65 + $columnIndex).($rowIndex + 1);
            str_starts_with($value, '=')
                ? $sheet->setCellValue($coordinate, $value)
                : $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    (new Xlsx($book))->save($path);

    if ($tamper !== null) {
        $tamper($path);
    }

    return new UploadedFile($path, $name, null, null, true);
}

function importCsv(string $content, string $name = 'contactos.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'import');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Upload a file as the given user and return the draft transfer.
 */
function uploadImport(User $user, UploadedFile $file): DataTransfer
{
    test()->actingAs($user)->post(route('contacts.import.store'), ['file' => $file])->assertSessionHasNoErrors();

    return DataTransfer::query()->where('type', DataTransferType::Import)->latest('id')->firstOrFail();
}

/**
 * Upload, keep the suggested mapping, choose the decision and confirm.
 */
function runImport(User $user, UploadedFile $file, string $decision = 'skip'): DataTransfer
{
    $transfer = uploadImport($user, $file);

    test()->actingAs($user)->put(route('contacts.import.update', $transfer), [
        'mapping' => $transfer->options['mapping'],
        'decision' => $decision,
    ])->assertSessionHasNoErrors();

    test()->actingAs($user)->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    return $transfer->refresh();
}

function resultSheetRows(DataTransfer $transfer): array
{
    $path = Storage::disk('local')->path((string) $transfer->path);

    return IOFactory::load($path)->getSheetByName('Detalle')->toArray(null, false, false, false);
}

const IMPORT_HEADERS = ['Nombre completo', 'Celular', 'Correo', 'Etiquetas', 'Comentarios'];

test('only the superadministrator can use the importer', function () {
    $file = importCsv("Nombre,Teléfono\nAna,5512345678\n");

    foreach ([User::factory()->administrator()->create(), User::factory()->agent()->create()] as $user) {
        $this->actingAs($user)->get(route('contacts.import.create'))->assertForbidden();
        $this->actingAs($user)->get(route('contacts.import.template'))->assertForbidden();
        $this->actingAs($user)->post(route('contacts.import.store'), ['file' => $file])->assertForbidden();
    }

    expect(DataTransfer::query()->count())->toBe(0);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('contacts.import.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('contacts/Import')->where('step', 'upload'));
});

test('an import belongs to whoever uploaded it', function () {
    $owner = User::factory()->superAdministrator()->create();
    $transfer = uploadImport($owner, importCsv("Nombre,Teléfono\nAna,5512345678\n"));

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('contacts.import.show', $transfer))
        ->assertNotFound();

    $this->actingAs(User::factory()->administrator()->create())
        ->post(route('contacts.import.confirm', $transfer))
        ->assertNotFound();
});

test('the template has the importable columns, an example and instructions', function () {
    $response = $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('contacts.import.template'))
        ->assertOk()
        ->assertDownload('plantilla-importacion-contactos.xlsx');

    $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

    expect($book->getSheetNames())->toBe(['Contactos', 'Instrucciones'])
        ->and($book->getSheet(0)->rangeToArray('A1:E1')[0])->toBe(['Nombre', 'Teléfono', 'Correo electrónico', 'Etiquetas', 'Notas'])
        ->and($book->getSheet(0)->getCell('A2')->getValue())->toBe('María López');
});

test('unsafe or invalid files are rejected before anything is stored', function (Closure $file, string $message) {
    config(['imports.max_rows' => 3, 'imports.max_kilobytes' => 1024]);

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->post(route('contacts.import.store'), ['file' => $file()])
        ->assertSessionHasErrors(['file' => $message]);

    expect(DataTransfer::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'wrong extension' => [fn () => importCsv("Nombre\nAna\n", 'contactos.txt'), 'Solo se aceptan archivos .xlsx o .csv (los libros con macros no se aceptan).'],
    'macro-enabled extension' => [fn () => importWorkbook([['Nombre'], ['Ana']], 'contactos.xlsm'), 'Solo se aceptan archivos .xlsx o .csv (los libros con macros no se aceptan).'],
    'macros inside an .xlsx' => [fn () => importWorkbook([['Nombre'], ['Ana']], tamper: function (string $path): void {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/vbaProject.bin', 'macro');
        $zip->close();
    }), 'El archivo contiene macros o controles ActiveX y no puede importarse. Guárdalo como libro de Excel (.xlsx) sin macros.'],
    'oversized' => [fn () => importCsv("Nombre,Teléfono\n".str_repeat("Ana,5512345678\n", 80000)), 'El archivo no puede pesar más de 1 MB.'],
    'too many rows' => [fn () => importCsv("Nombre,Teléfono\n".implode("\n", array_map(fn (int $i): string => "Persona {$i},55123456{$i}0", range(1, 5)))), 'El archivo tiene 5 filas y el máximo es 3. Divide el archivo e impórtalo por partes.'],
    'binary disguised as csv' => [fn () => importCsv("PK\x03\x04binario\0"), 'El archivo .csv no es texto plano. Si es un libro de Excel, súbelo con extensión .xlsx.'],
    'no data rows' => [fn () => importCsv("Nombre,Teléfono\n"), 'El archivo no tiene filas con datos debajo de los encabezados.'],
]);

test('the preview suggests the mapping and validates every row', function () {
    $root = User::factory()->superAdministrator()->create();
    Tag::factory()->create(['name' => 'Mayorista']);
    Tag::factory()->inactive()->create(['name' => 'Antigua']);
    Contact::factory()->create(['name' => 'Ya registrada', 'phone_e164' => '+525511112222']);

    $transfer = uploadImport($root, importWorkbook([
        IMPORT_HEADERS,
        ['Ana Ruiz', '55 1234 5678', 'ana@ejemplo.com', 'mayorista', 'Llamar por la tarde'],
        ['Teléfono inválido', '123', '', '', ''],
        ['Correo inválido', '', 'no-es-correo', '', ''],
        ['Etiquetas malas', '5523456789', '', 'Inexistente, Antigua', ''],
        ['=HYPERLINK("http://x","clic")', '5534567890', '', '', ''],
        ['Ana repetida', '+52 55 1234 5678', '', '', ''],
        ['Existente', '5511112222', '', '', ''],
    ]));

    expect($transfer->status)->toBe(DataTransferStatus::Draft)
        ->and($transfer->options['mapping'])->toBe(['name' => 0, 'phone' => 1, 'email' => 2, 'tags' => 3, 'notes' => 4]);

    $this->actingAs($root)->get(route('contacts.import.show', $transfer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Import')
            ->where('step', 'mapping')
            ->where('preview.summary', ['total' => 7, 'valid' => 3, 'new' => 2, 'existing' => 1, 'duplicates_in_file' => 1, 'errors' => 3, 'warnings' => 1])
            ->where('preview.rows.0.tags', ['Mayorista'])
            ->where('preview.rows.1.errors', ['Teléfono no válido: escribe 10 dígitos para México o el número completo con lada internacional.'])
            ->where('preview.rows.2.errors', ['Correo electrónico no válido.'])
            ->where('preview.rows.3.errors', ['La etiqueta «Inexistente» no existe.', 'La etiqueta «Antigua» está eliminada y no puede asignarse.'])
            ->where('preview.rows.4.name', '=HYPERLINK("http://x","clic")')
            ->where('preview.rows.4.warnings', ['La columna «Nombre» empieza con «=»: se importará como texto, nunca como fórmula.'])
            ->where('preview.rows.5.status', 'duplicate')
            ->where('preview.rows.5.duplicate_of_line', 2)
            ->where('preview.rows.6.existing.0.name', 'Ya registrada'));
});

test('a custom mapping is saved and an unusable one is refused', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = uploadImport($root, importCsv("Persona;Dato A;Dato B\nAna;ana@ejemplo.com;5512345678\n"));

    expect($transfer->options['mapping'])->toBe(['name' => null, 'phone' => null, 'email' => null, 'tags' => null, 'notes' => null]);

    $this->actingAs($root)->put(route('contacts.import.update', $transfer), [
        'mapping' => ['name' => 0, 'phone' => 2, 'email' => 2],
        'decision' => 'skip',
    ])->assertSessionHasErrors(['mapping' => 'Cada columna del archivo solo puede asignarse a un campo.']);

    $this->actingAs($root)->put(route('contacts.import.update', $transfer), [
        'mapping' => ['name' => 0, 'tags' => 1],
        'decision' => 'skip',
    ])->assertSessionHasErrors(['mapping' => 'Asigna la columna del teléfono o la del correo electrónico: cada contacto necesita al menos uno.']);

    $this->actingAs($root)->put(route('contacts.import.update', $transfer), [
        'mapping' => ['name' => 0, 'phone' => 2, 'email' => 1],
        'decision' => 'fill_empty',
    ])->assertSessionHasNoErrors();

    expect($transfer->refresh()->options)->toMatchArray([
        'mapping' => ['name' => 0, 'phone' => 2, 'email' => 1, 'tags' => null, 'notes' => null],
        'decision' => 'fill_empty',
    ]);

    $this->actingAs($root)->post(route('contacts.import.confirm', $transfer))->assertSessionHasNoErrors();

    expect(Contact::query()->where('email', 'ana@ejemplo.com')->sole()->phone_e164)->toBe('+525512345678');
});

test('confirming processes the rows in batches through the contact actions and leaves a result report', function () {
    config(['imports.batch_size' => 2]);
    $root = User::factory()->superAdministrator()->create();
    $tag = Tag::factory()->create(['name' => 'Mayorista']);
    $existing = Contact::factory()->create(['name' => 'Ya registrada', 'phone_e164' => '+525511112222', 'email' => null]);

    $transfer = runImport($root, importWorkbook([
        IMPORT_HEADERS,
        ['Ana Ruiz', '5512345678', 'ANA@ejemplo.com', 'Mayorista', 'Llamar por la tarde'],
        ['=HYPERLINK("http://x","clic")', '5534567890', '', '', ''],
        ['Sin teléfono', '123', '', '', ''],
        ['Otra Ana', '5512345678', '', '', ''],
        ['Distinto nombre', '5511112222', 'nuevo@ejemplo.com', '', ''],
    ]));

    expect($transfer->status)->toBe(DataTransferStatus::Completed)
        ->and($transfer->rows_processed)->toBe(5)
        ->and($transfer->rows_failed)->toBe(1)
        ->and($transfer->summary)->toBe(['created' => 2, 'updated' => 0, 'skipped' => 2, 'failed' => 1])
        ->and($transfer->options['source_path'])->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([$transfer->path]);

    $ana = Contact::query()->where('phone_e164', '+525512345678')->sole();

    expect($ana->source)->toBe(ContactSource::Import)
        ->and($ana->email)->toBe('ana@ejemplo.com')
        ->and($ana->created_by_user_id)->toBe($root->id)
        ->and($ana->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($ana->notes()->sole()->body)->toBe('Llamar por la tarde')
        ->and(Contact::query()->where('phone_e164', '+525534567890')->sole()->name)->toBe('=HYPERLINK("http://x","clic")')
        ->and($existing->refresh()->email)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ContactCreated)->count())->toBe(2);

    $rows = resultSheetRows($transfer);

    expect($rows[0])->toBe(['Fila', 'Resultado', 'Nombre', 'Teléfono', 'Correo electrónico', 'Contacto', 'Motivo'])
        ->and(array_column(array_slice($rows, 1), 1))->toBe(['Creado', 'Creado', 'Con error', 'Omitido', 'Omitido'])
        ->and($rows[2][2])->toBe('=HYPERLINK("http://x","clic")')
        ->and($rows[4][6])->toBe('Repetido en el archivo: es el mismo contacto de la fila 2.')
        ->and($rows[5][6])->toBe("Ya existe como «Ya registrada» (#{$existing->id}).");

    $book = IOFactory::load(Storage::disk('local')->path((string) $transfer->path));
    expect($book->getSheetByName('Detalle')->getCell('C3')->getDataType())->toBe(DataType::TYPE_STRING);

    $this->actingAs($root)->get(route('contacts.import.report', $transfer))->assertOk()->assertDownload();
});

test('completing duplicates only fills empty fields and never overwrites', function () {
    $root = User::factory()->superAdministrator()->create();
    $withoutEmail = Contact::factory()->create(['name' => 'Sin correo', 'phone_e164' => '+525511112222', 'email' => null]);
    $withEmail = Contact::factory()->create(['name' => 'Con correo', 'phone_e164' => '+525533334444', 'email' => 'original@ejemplo.com']);

    $transfer = runImport($root, importCsv(
        "Nombre,Teléfono,Correo\n".
        "Nombre nuevo,5511112222,completa@ejemplo.com\n".
        "Otro nombre,5533334444,cambio@ejemplo.com\n",
    ), 'fill_empty');

    expect($transfer->summary)->toBe(['created' => 0, 'updated' => 1, 'skipped' => 1, 'failed' => 0])
        ->and($withoutEmail->refresh()->only(['name', 'email']))->toBe(['name' => 'Sin correo', 'email' => 'completa@ejemplo.com'])
        ->and($withEmail->refresh()->only(['name', 'email']))->toBe(['name' => 'Con correo', 'email' => 'original@ejemplo.com']);
});

test('importing the same file again never duplicates contacts', function () {
    $root = User::factory()->superAdministrator()->create();
    $content = "Nombre,Teléfono,Correo\nAna,5512345678,ana@ejemplo.com\nLuis,5587654321,\n";

    $first = runImport($root, importCsv($content));
    $second = uploadImport($root, importCsv($content));

    $this->actingAs($root)->get(route('contacts.import.show', $second))
        ->assertInertia(fn (Assert $page) => $page
            ->where('previousImport.summary.created', 2)
            ->where('preview.summary.existing', 2));

    $this->actingAs($root)->post(route('contacts.import.confirm', $second))->assertSessionHasNoErrors();

    expect($first->summary['created'])->toBe(2)
        ->and($second->refresh()->summary)->toBe(['created' => 0, 'updated' => 0, 'skipped' => 2, 'failed' => 0])
        ->and(Contact::query()->count())->toBe(2);

    // A repeated job for an already processed import does nothing.
    ImportContacts::dispatchSync($first->id);

    expect(Contact::query()->count())->toBe(2);
});

test('a confirmed import cannot be confirmed or changed again', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = runImport($root, importCsv("Nombre,Teléfono\nAna,5512345678\n"));

    $this->actingAs($root)->post(route('contacts.import.confirm', $transfer))
        ->assertSessionHasErrors(['file' => 'Esta importación ya fue confirmada o cancelada.']);

    expect(Contact::query()->count())->toBe(1);
});

test('a draft can be cancelled and its file is deleted', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = uploadImport($root, importCsv("Nombre,Teléfono\nAna,5512345678\n"));

    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    $this->actingAs($root)->delete(route('contacts.import.destroy', $transfer))->assertRedirect(route('contacts.import.create'));

    expect($transfer->refresh()->status)->toBe(DataTransferStatus::Expired)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('import audit entries are only readable by the superadministrator', function () {
    $root = User::factory()->superAdministrator()->create();
    runImport($root, importCsv("Nombre,Teléfono\nAna,5512345678\n"));

    $entries = AuditLog::query()->whereIn('action', [AuditAction::ImportRequested, AuditAction::ImportCompleted])->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->every(fn (AuditLog $log): bool => $log->restricted))->toBeTrue()
        ->and(AuditLog::query()->visibleTo(User::factory()->administrator()->create())->whereIn('action', [AuditAction::ImportRequested, AuditAction::ImportCompleted])->exists())->toBeFalse()
        ->and(AuditLog::query()->visibleTo($root)->whereIn('action', [AuditAction::ImportRequested, AuditAction::ImportCompleted])->count())->toBe(2);
});

test('imports are not listed nor downloadable as exports', function () {
    $root = User::factory()->superAdministrator()->create();
    $transfer = runImport($root, importCsv("Nombre,Teléfono\nAna,5512345678\n"));

    $this->actingAs($root)->get(route('exports.index'))
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 0));

    $this->actingAs($root)->get(route('exports.transfers.download', $transfer))->assertNotFound();
});

test('completing a row that matches a provisional contact saves it into the directory', function () {
    $root = User::factory()->superAdministrator()->create();
    $provisional = Contact::factory()->provisional()->create(['name' => 'Escribió por WhatsApp', 'phone_e164' => '+525511112222', 'email' => null]);

    $transfer = runImport($root, importCsv("Nombre,Teléfono,Correo\nNombre del archivo,5511112222,\n"), 'fill_empty');

    expect($transfer->summary)->toBe(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0])
        ->and($provisional->refresh()->isSaved())->toBeTrue()
        ->and($provisional->saved_by_user_id)->toBe($root->id)
        ->and($provisional->name)->toBe('Escribió por WhatsApp')
        ->and(AuditLog::query()->where('action', AuditAction::ContactSaved)->sole()->properties)->toMatchArray(['via' => 'import']);
});

test('imported contacts are saved into the directory', function () {
    $root = User::factory()->superAdministrator()->create();

    runImport($root, importCsv("Nombre,Teléfono,Correo\nAna,5512345678,ana@ejemplo.com\n"));

    expect(Contact::query()->where('name', 'Ana')->sole())
        ->isSaved()->toBeTrue()
        ->saved_by_user_id->toBe($root->id);
});
