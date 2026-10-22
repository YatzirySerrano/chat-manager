<?php

use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\DataTransferStatus;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Jobs\GenerateReport;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Reports\Definitions\TagsReport;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\Rendering\ReportPalette;
use App\Services\Exports\ReportExporter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A global user that can consult tags and contacts, and export.
 */
function tagExporter(): User
{
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ViewContacts, Permission::ExportReports])->create();

    return User::factory()->withRole($role)->create();
}

/**
 * Open the workbook sent by a synchronous download and remove the file.
 */
function downloadedWorkbook(BinaryFileResponse $response): Spreadsheet
{
    $path = $response->getFile()->getPathname();
    $reader = IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);
    $workbook = $reader->load($path);
    @unlink($path);

    return $workbook;
}

test('exporting requires the export permission and the module permission', function () {
    Tag::factory()->create();
    $supervisor = User::factory()->supervisor()->create();
    $exportOnly = User::factory()->withRole(
        Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ExportReports])->create(),
    )->create();

    $this->actingAs($supervisor)->get(route('exports.generate', ['tags', 'xlsx']))->assertForbidden();
    $this->actingAs($exportOnly)->get(route('exports.generate', ['tags', 'pdf']))->assertForbidden();

    expect(AuditLog::query()->where('action', AuditAction::ExportGenerated)->exists())->toBeFalse();
});

test('unknown reports and formats are not found', function () {
    $exporter = tagExporter();

    $this->actingAs($exporter)->get('/exports/unknown/xlsx')->assertNotFound();
    $this->actingAs($exporter)->get('/exports/tags/csv')->assertNotFound();
});

test('a small export downloads a styled workbook that never evaluates formulas', function () {
    $exporter = tagExporter();
    $contacts = Contact::factory()->count(2)->create();
    Tag::factory()->create(['name' => 'Mayorista'])->contacts()->attach($contacts->pluck('id'));
    Tag::factory()->create(['name' => '=HYPERLINK("http://x")', 'description' => '+cmd|calc']);

    $response = $this->actingAs($exporter)->get(route('exports.generate', ['tags', 'xlsx']));

    $response->assertOk()
        ->assertHeader('Content-Type', ExportFormat::Xlsx->mimeType())
        ->assertDownload('etiquetas-'.now('America/Mexico_City')->format('Y-m-d-Hi').'.xlsx');

    $workbook = downloadedWorkbook($response->baseResponse);
    $sheet = $workbook->getSheetByName('Etiquetas');

    expect($workbook->getSheetNames())->toBe(['Resumen', 'Etiquetas'])
        ->and($workbook->getSheetByName('Resumen')->getChartCollection())->toHaveCount(1)
        ->and($sheet->rangeToArray('A6:I6')[0])->toBe(['Nombre', 'Identificador', 'Color', 'Descripción', 'Estado', 'Contactos', 'Conversaciones', 'Creada por', 'Fecha de creación'])
        ->and($sheet->getStyle('A6')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getStyle('A6')->getFont()->getColor()->getRGB())->toBe('FFFFFF')
        ->and($sheet->getStyle('A6')->getFill()->getStartColor()->getRGB())->toBe(ReportPalette::rgb(ReportPalette::ACCENT))
        ->and($sheet->getAutoFilter()->getRange())->toBe('A6:I8')
        ->and($sheet->getFreezePane())->toBe('A7')
        ->and($sheet->getCell('A9')->getValue())->toBe('Total')
        ->and($sheet->getCell('F9')->getCalculatedValue())->toEqual(2);

    $formula = $sheet->getCell('A7');

    expect($formula->getValue())->toBe('=HYPERLINK("http://x")')
        ->and($formula->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($formula->isFormula())->toBeFalse()
        ->and($sheet->getCell('D7')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A8')->getValue())->toBe('Mayorista')
        ->and($sheet->getCell('F8')->getValue())->toEqual(2);

    $log = AuditLog::query()->where('action', AuditAction::ExportGenerated)->sole();

    expect($log->user_id)->toBe($exporter->id)
        ->and($log->properties)->toMatchArray(['report' => 'tags', 'format' => 'xlsx', 'rows' => 2, 'filters' => [], 'mode' => 'sync']);
});

test('the export applies the filters and record state of the tags screen', function () {
    $exporter = tagExporter();
    Tag::factory()->create(['name' => 'Mayorista']);
    Tag::factory()->create(['name' => 'Mayoreo antiguo', 'is_active' => false]);
    Tag::factory()->create(['name' => 'Prospecto']);

    // Without records.view-deleted, asking for deleted tags still exports the active ones.
    $response = $this->actingAs($exporter)
        ->get(route('exports.generate', ['tags', 'xlsx', 'search' => 'mayor', 'record' => 'deleted']))
        ->assertOk();

    $sheet = downloadedWorkbook($response->baseResponse)->getSheetByName('Etiquetas');

    expect($sheet->getCell('A7')->getValue())->toBe('Mayorista')
        ->and($sheet->getCell('A8')->getValue())->toBe('Total')
        ->and($sheet->getCell('A4')->getValue())->toBe('Filtros: Búsqueda: «mayor»');

    $auditor = User::factory()->withRole(
        Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ExportReports, Permission::ViewDeletedRecords])->create(),
    )->create();

    $response = $this->actingAs($auditor)
        ->get(route('exports.generate', ['tags', 'xlsx', 'search' => 'mayor', 'record' => 'deleted']))
        ->assertOk();

    $sheet = downloadedWorkbook($response->baseResponse)->getSheetByName('Etiquetas');

    expect($sheet->getCell('A7')->getValue())->toBe('Mayoreo antiguo')
        ->and($sheet->getCell('E7')->getValue())->toBe('Eliminada')
        ->and($sheet->getCell('A4')->getValue())->toBe('Filtros: Búsqueda: «mayor» · Estado del registro: Eliminadas');

    $this->actingAs($exporter)
        ->get(route('exports.generate', ['tags', 'xlsx', 'record' => 'archived']))
        ->assertSessionHasErrors('record');
});

test('usage counts only include contacts within the actor scope', function () {
    $role = Role::factory()->scope(DataScope::Assigned)->withPermissions([Permission::ViewTags, Permission::ViewContacts, Permission::ExportReports])->create();
    $agent = User::factory()->withRole($role)->create();
    $tag = Tag::factory()->create();
    $tag->contacts()->attach(Contact::factory()->count(3)->create()->pluck('id'));

    $response = $this->actingAs($agent)->get(route('exports.generate', ['tags', 'xlsx']))->assertOk();

    expect(downloadedWorkbook($response->baseResponse)->getSheetByName('Etiquetas')->getCell('F7')->getValue())->toEqual(0);
});

test('exports above the hard limit are refused with an explanation', function () {
    config(['exports.formats.xlsx.max_rows' => 1]);
    $exporter = tagExporter();
    Tag::factory()->count(2)->create();

    $this->actingAs($exporter)
        ->from(route('tags.index'))
        ->get(route('exports.generate', ['tags', 'xlsx']))
        ->assertRedirect(route('tags.index'))
        ->assertSessionHasErrors(['export' => 'El reporte tiene 2 registros y el máximo para Excel (.xlsx) es 1. Aplica filtros para reducirlo e inténtalo de nuevo.']);

    expect(AuditLog::query()->where('action', AuditAction::ExportGenerated)->exists())->toBeFalse();
});

test('large exports are queued for the requester', function () {
    Queue::fake();
    config(['exports.formats.xlsx.sync_rows' => 1, 'exports.queue' => 'exports']);
    $exporter = tagExporter();
    Tag::factory()->count(2)->create(['description' => 'Clientes']);

    $this->actingAs($exporter)
        ->from(route('tags.index'))
        ->get(route('exports.generate', ['tags', 'xlsx', 'search' => 'Clientes']))
        ->assertRedirect(route('tags.index'));

    $transfer = DataTransfer::query()->sole();

    expect($transfer)
        ->user_id->toBe($exporter->id)
        ->report->toBe('tags')
        ->format->toBe(ExportFormat::Xlsx)
        ->status->toBe(DataTransferStatus::Queued)
        ->filters->toBe(['search' => 'Clientes'])
        ->rows_total->toBe(2);

    Queue::assertPushedOn('exports', GenerateReport::class, fn (GenerateReport $job) => $job->transferId === $transfer->id);

    expect(AuditLog::query()->where('action', AuditAction::ExportRequested)->sole()->properties)
        ->toMatchArray(['report' => 'tags', 'format' => 'xlsx', 'rows' => 2, 'filters' => ['Búsqueda: «Clientes»'], 'mode' => 'queued', 'transfer_id' => $transfer->id]);
});

test('the job renders the file privately and only its owner can download it', function () {
    Storage::fake('local');
    $exporter = tagExporter();
    Tag::factory()->count(3)->create();
    $transfer = DataTransfer::factory()->for($exporter)->create(['filters' => []]);

    GenerateReport::dispatchSync($transfer->id);

    $transfer->refresh();

    expect($transfer->status)->toBe(DataTransferStatus::Completed)
        ->and($transfer->rows_total)->toBe(3)
        ->and($transfer->path)->toStartWith("exports/{$exporter->id}/")
        ->and($transfer->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();
    Storage::disk('local')->assertExists($transfer->path);

    expect(AuditLog::query()->where('action', AuditAction::ExportGenerated)->sole()->properties)
        ->toMatchArray(['mode' => 'queued', 'rows' => 3, 'transfer_id' => $transfer->id]);

    $this->actingAs($exporter)
        ->get(route('exports.transfers.download', $transfer))
        ->assertOk()
        ->assertDownload($transfer->file_name);

    $this->actingAs(tagExporter())
        ->get(route('exports.transfers.download', $transfer))
        ->assertNotFound();
});

test('expired or unfinished transfers cannot be downloaded', function () {
    $exporter = tagExporter();
    $expired = DataTransfer::factory()->for($exporter)->pastExpiration()->create();
    $queued = DataTransfer::factory()->for($exporter)->create();

    $this->actingAs($exporter)->get(route('exports.transfers.download', $expired))->assertStatus(410);
    $this->actingAs($exporter)->get(route('exports.transfers.download', $queued))->assertStatus(410);
});

test('the job fails without a file when the requester lost the permission', function () {
    Storage::fake('local');
    $role = Role::factory()->scope(DataScope::Global)->withPermissions([Permission::ViewTags, Permission::ExportReports])->create();
    $exporter = User::factory()->withRole($role)->create();
    $transfer = DataTransfer::factory()->for($exporter)->create();
    $role->revokePermissionTo(Permission::ExportReports->value);

    GenerateReport::dispatchSync($transfer->id);

    expect($transfer->refresh())
        ->status->toBe(DataTransferStatus::Failed)
        ->path->toBeNull()
        ->error->toBe('Ya no tienes permiso para exportar esta información.');
    expect(AuditLog::query()->where('action', AuditAction::ExportFailed)->exists())->toBeTrue();
});

test('expired files are deleted and their transfers kept as expired', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/1/old.xlsx', 'old');
    Storage::disk('local')->put('exports/1/current.xlsx', 'current');
    $old = DataTransfer::factory()->pastExpiration()->create(['path' => 'exports/1/old.xlsx']);
    $current = DataTransfer::factory()->completed('exports/1/current.xlsx')->create();

    $this->artisan('exports:prune')->expectsOutputToContain('Exportaciones expiradas: 1')->assertSuccessful();

    Storage::disk('local')->assertMissing('exports/1/old.xlsx');
    Storage::disk('local')->assertExists('exports/1/current.xlsx');
    expect($old->refresh())->status->toBe(DataTransferStatus::Expired)->path->toBeNull()
        ->and($current->refresh()->status)->toBe(DataTransferStatus::Completed);
});

test('the exports page lists only the user own transfers', function () {
    $exporter = tagExporter();
    $mine = DataTransfer::factory()->for($exporter)->completed()->create();
    DataTransfer::factory()->for(tagExporter())->completed()->create();

    $this->actingAs($exporter)
        ->get(route('exports.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('exports/Index')
            ->has('transfers.data', 1)
            ->where('transfers.data.0.id', $mine->id)
            ->where('transfers.data.0.report_title', 'Etiquetas')
            ->where('transfers.data.0.download_url', route('exports.transfers.download', $mine)));

    $this->actingAs(User::factory()->supervisor()->create())->get(route('exports.index'))->assertForbidden();
});

test('the export permission can be granted from the roles screen', function () {
    $admin = User::factory()->superAdministrator()->create();

    $this->actingAs($admin)
        ->post(route('admin.roles.store'), [
            'label' => 'Analista',
            'scope' => 'global',
            'permissions' => ['tags.view', 'reports.export'],
        ])
        ->assertSessionHasNoErrors();

    expect(Role::query()->where('label', 'Analista')->sole()->permissionNames())->toContain('reports.export');
});

test('the PDF document escapes data and shows filters and metrics', function () {
    $exporter = tagExporter();
    Tag::factory()->create(['name' => '<script>alert(1)</script>']);
    Tag::factory()->create(['name' => 'Retirada', 'is_active' => false]);

    $exporterService = app(ReportExporter::class);
    $definition = app(TagsReport::class);
    $html = app(PdfRenderer::class)->html($definition, $exporterService->prepare($definition, $exporter, ['search' => '<b>x']));

    expect($html)->toContain('Búsqueda: «&lt;b&gt;x»')
        ->not->toContain('<b>x');

    $html = app(PdfRenderer::class)->html($definition, $exporterService->prepare($definition, $exporter, []));

    expect($html)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('Sin filtros')
        ->not->toContain('Retirada')
        ->not->toContain('Eliminadas')
        ->toContain('<thead>')
        ->toContain("default-src 'none'");
});

test('a real PDF is printed with Chrome', function () {
    $exporter = tagExporter();
    Tag::factory()->count(3)->create();

    $response = $this->actingAs($exporter)->get(route('exports.generate', ['tags', 'pdf']))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();

    expect(file_get_contents($path, length: 4))->toBe('%PDF');
    @unlink($path);
})->skip(
    fn () => ! is_executable((string) config('exports.pdf.node_binary')) || ! is_executable((string) config('exports.pdf.chrome_path')),
    'Node or Chrome are not configured for this environment (EXPORTS_NODE_BINARY, EXPORTS_CHROME_PATH).',
)->group('chrome');
