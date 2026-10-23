<?php

use App\Enums\AuditAction;
use App\Enums\ContactStatus;
use App\Enums\ConversationEventType;
use App\Enums\DataScope;
use App\Enums\DataTransferStatus;
use App\Enums\ExportFormat;
use App\Enums\MessageDirection;
use App\Enums\Permission;
use App\Jobs\GenerateReport;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\DataTransfer;
use App\Models\Department;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Reports\Definitions\ConversationDossierReport;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * A global user holding the given permissions.
 *
 * @param  list<Permission>  $permissions
 */
function reportUser(array $permissions, DataScope $scope = DataScope::Global): User
{
    return User::factory()->withRole(Role::factory()->scope($scope)->withPermissions($permissions)->create())->create();
}

/**
 * Render a report to a temporary file and return its path.
 *
 * @param  array<string, mixed>  $input
 */
function renderReport(string $key, User $actor, ExportFormat $format, array $input = []): string
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find($key);
    $path = $exporter->temporaryPath($format);
    $exporter->render($definition, $exporter->prepare($definition, $actor, $input), $format, $path);

    return $path;
}

function reportWorkbook(string $path): Spreadsheet
{
    $reader = IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);

    return $reader->load($path);
}

/**
 * Every value written in the workbook (all sheets), to look for data that
 * must never be exported.
 */
function reportWorkbookText(string $path): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $text = '';

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $text .= (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $text;
}

/**
 * @param  array<string, mixed>  $input
 */
function reportHtml(string $key, User $actor, array $input = []): string
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find($key);

    return app(PdfRenderer::class)->html($definition, $exporter->prepare($definition, $actor, $input));
}

/**
 * Module permission of each report (besides the export permission).
 *
 * @return array<string, array{0: string, 1: list<Permission>}>
 */
dataset('module reports', [
    'dashboard' => ['dashboard', [Permission::ViewDashboard]],
    'conversations' => ['conversations', [Permission::ViewConversations]],
    'contacts' => ['contacts', [Permission::ViewContacts]],
    'tags' => ['tags', [Permission::ViewTags]],
    'quick-replies' => ['quick-replies', [Permission::ManageQuickReplies]],
    'departments' => ['departments', [Permission::ViewDepartments]],
    'users' => ['users', [Permission::ViewUsers]],
    'roles' => ['roles', [Permission::ViewRoles]],
    'connections' => ['connections', [Permission::ViewConnections]],
]);

test('every module report requires reports.export and the module permission', function (string $key, array $view) {
    $this->actingAs(reportUser($view))->post(route('exports.generate', [$key, 'xlsx']))->assertForbidden();
    $this->actingAs(reportUser([Permission::ExportReports]))->post(route('exports.generate', [$key, 'pdf']))->assertForbidden();
    $this->actingAs(reportUser([...$view, Permission::ExportReports]))->post(route('exports.generate', [$key, 'xlsx']))->assertOk();
})->with('module reports');

test('every module report produces a styled workbook and a PDF document', function (string $key, array $view) {
    $department = Department::factory()->create(['name' => 'Ventas']);
    $tag = Tag::factory()->create(['name' => 'Mayorista']);
    $contact = Contact::factory()->create(['name' => 'Ana Pérez', 'phone_e164' => '+525512345678']);
    $contact->tags()->attach($tag);
    Conversation::factory()->for($contact)->inDepartment($department)->create();
    QuickReply::factory()->create(['title' => 'Saludo']);
    Connection::factory()->create()->departments()->attach($department);
    $actor = reportUser([...$view, Permission::ExportReports]);

    $path = renderReport($key, $actor, ExportFormat::Xlsx);
    $workbook = reportWorkbook($path);
    $definition = app(ReportRegistry::class)->find($key);
    $data = $workbook->getSheetByName(mb_substr($definition->title(), 0, 31));
    @unlink($path);

    expect($workbook->getSheetNames()[0])->toBe('Resumen')
        ->and($data)->not->toBeNull()
        ->and($data->getCell('A2')->getValue())->toBe($definition->title())
        ->and($data->getCell('A6')->getValue())->toBe($definition->columns()[0]->label)
        ->and($data->getStyle('A6')->getFont()->getBold())->toBeTrue()
        ->and($data->getAutoFilter()->getRange())->toStartWith('A6:')
        ->and($data->getFreezePane())->toBe('A7');

    expect(reportHtml($key, $actor))
        ->toContain('<h1>'.e($definition->title()).'</h1>')
        ->toContain('class="metric"')
        ->toContain("default-src 'none'");
})->with('module reports');

test('dates are real dates, phones are formatted and formula payloads stay text', function () {
    $actor = reportUser([Permission::ViewContacts, Permission::ExportReports]);
    Contact::factory()->create(['name' => '=HYPERLINK("http://evil")', 'phone_e164' => '+525512345678', 'email' => '@SUM(1+1)']);

    $path = renderReport('contacts', $actor, ExportFormat::Xlsx);
    $sheet = reportWorkbook($path)->getSheetByName('Contactos');
    @unlink($path);

    expect($sheet->getCell('A7')->getValue())->toBe('=HYPERLINK("http://evil")')
        ->and($sheet->getCell('A7')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A7')->isFormula())->toBeFalse()
        ->and($sheet->getStyle('A7')->getQuotePrefix())->toBeTrue()
        ->and($sheet->getCell('C7')->getValue())->toBe('@SUM(1+1)')
        ->and($sheet->getCell('B7')->getValue())->toBe('+52 55 1234 5678')
        ->and($sheet->getCell('J7')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getStyle('J7')->getNumberFormat()->getFormatCode())->toBe('dd/mm/yyyy hh:mm');
});

test('the contacts report mirrors the screen filters and hides deleted contacts without permission', function () {
    $tag = Tag::factory()->create(['name' => 'VIP']);
    Contact::factory()->create(['name' => 'Cliente VIP'])->tags()->attach($tag);
    Contact::factory()->create(['name' => 'Cliente común']);
    Contact::factory()->create(['name' => 'Cliente archivado', 'status' => ContactStatus::Archived, 'archived_at' => now()]);
    $viewer = reportUser([Permission::ViewContacts, Permission::ExportReports]);
    $auditor = reportUser([Permission::ViewContacts, Permission::ExportReports, Permission::ViewDeletedRecords]);

    $path = renderReport('contacts', $viewer, ExportFormat::Xlsx, ['tags' => [$tag->id]]);
    $sheet = reportWorkbook($path)->getSheetByName('Contactos');
    @unlink($path);

    expect($sheet->getCell('A7')->getValue())->toBe('Cliente VIP')
        ->and($sheet->getCell('A8')->getValue())->toBeNull()
        ->and($sheet->getCell('A4')->getValue())->toContain('Etiquetas: VIP');

    expect(reportHtml('contacts', $viewer, ['record' => 'deleted']))->not->toContain('Cliente archivado')
        ->and(reportHtml('contacts', $auditor, ['record' => 'deleted']))->toContain('Cliente archivado')->toContain('Estado del registro: Eliminados');
});

test('an agent only exports their own conversations, without message bodies', function () {
    $agent = reportUser([Permission::ViewConversations, Permission::ExportReports], DataScope::Assigned);
    $own = Conversation::factory()->assignedTo($agent)->for(Contact::factory()->state(['name' => 'Cliente propio']))->create();
    Conversation::factory()->assignedTo(User::factory()->agent()->create())->for(Contact::factory()->state(['name' => 'Cliente ajeno']))->create();
    Message::factory()->for($own)->create(['body' => 'Mi tarjeta termina en 4242']);

    $path = renderReport('conversations', $agent, ExportFormat::Xlsx);
    $text = reportWorkbookText($path);
    $sheet = reportWorkbook($path)->getSheetByName('Conversaciones');
    @unlink($path);

    expect($sheet->getCell('B7')->getValue())->toBe('Cliente propio')
        ->and($sheet->getCell('B8')->getValue())->toBeNull()
        ->and($text)->not->toContain('Cliente ajeno')
        ->and($text)->not->toContain('4242')
        ->and($sheet->getCell('A4')->getValue())->toContain('Vista: Asignadas a ti');
});

test('the conversations export says whether the search looked in the contact or in the messages', function (array $input, string $expected) {
    $path = renderReport('conversations', reportUser([Permission::ViewConversations, Permission::ExportReports]), ExportFormat::Xlsx, $input);
    $filters = (string) reportWorkbook($path)->getSheetByName('Conversaciones')->getCell('A4')->getValue();
    @unlink($path);

    expect($filters)->toContain($expected);
})->with([
    'contact' => [['search' => 'pedido'], 'Búsqueda: «pedido»'],
    'messages' => [['search' => 'pedido', 'search_in' => 'messages'], 'Búsqueda en el contenido de los mensajes: «pedido»'],
]);

test('the conversation dossier is authorized like the detail page', function () {
    $agent = reportUser([Permission::ViewConversations, Permission::ExportReports], DataScope::Assigned);
    $other = Conversation::factory()->assignedTo(User::factory()->agent()->create())->create();
    $own = Conversation::factory()->assignedTo($agent)->create();

    $this->actingAs($agent)
        ->post(route('exports.generate', ['conversation-dossier', 'pdf', 'conversation' => $other->id]))
        ->assertNotFound();
    $this->actingAs($agent)
        ->post(route('exports.generate', ['conversation-dossier', 'xlsx', 'conversation' => 999999]))
        ->assertNotFound();
    $this->actingAs($agent)
        ->post(route('exports.generate', ['conversation-dossier', 'xlsx']))
        ->assertSessionHasErrors('conversation');
    $this->actingAs(reportUser([Permission::ViewConversations], DataScope::Assigned))
        ->post(route('exports.generate', ['conversation-dossier', 'xlsx', 'conversation' => $own->id]))
        ->assertForbidden();

    $this->actingAs($agent)
        ->post(route('exports.generate', ['conversation-dossier', 'xlsx', 'conversation' => $own->id]))
        ->assertOk()
        ->assertDownload();

    expect(AuditLog::query()->where('action', AuditAction::ExportGenerated)->sole()->properties)
        ->toMatchArray(['report' => 'conversation-dossier', 'filters' => ["Conversación #{$own->id} · {$own->contact->name}"]]);
});

test('the dossier caps the messages and shows the timeline only with the history permission', function () {
    $withHistory = reportUser([Permission::ViewConversations, Permission::ViewConversationHistory, Permission::ExportReports]);
    $withoutHistory = reportUser([Permission::ViewConversations, Permission::ExportReports]);
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['body' => 'Mensaje más antiguo']);
    Message::factory()->count(ConversationDossierReport::MAX_MESSAGES)->for($conversation)->create(['direction' => MessageDirection::Inbound]);
    ConversationNote::factory()->for($conversation)->create(['body' => 'Nota interna del equipo']);
    $conversation->events()->create(['type' => ConversationEventType::Transferred, 'note' => 'Cambio de turno']);

    $html = reportHtml('conversation-dossier', $withHistory, ['conversation' => $conversation->id]);

    expect($html)->toContain('el expediente incluye los últimos 500')
        ->toContain('Nota interna del equipo')
        ->toContain('Línea de tiempo')
        ->toContain('Cambio de turno')
        ->not->toContain('Mensaje más antiguo');

    $path = renderReport('conversation-dossier', $withoutHistory, ExportFormat::Xlsx, ['conversation' => $conversation->id]);
    $workbook = reportWorkbook($path);
    @unlink($path);

    expect($workbook->getSheetNames())->toBe(['Resumen', 'Expediente de conversación', 'Notas internas'])
        ->and($workbook->getSheetByName('Expediente de conversación')->getHighestDataRow())->toBe(6 + ConversationDossierReport::MAX_MESSAGES);
});

test('the users report never contains credentials', function () {
    $admin = User::factory()->administrator()->create();
    $user = User::factory()->withTwoFactor()->create(['name' => 'Usuaria Segura', 'remember_token' => 'remember-me-token-123']);

    $path = renderReport('users', $admin, ExportFormat::Xlsx);
    $text = reportWorkbookText($path);
    $sheet = reportWorkbook($path)->getSheetByName('Usuarios');
    @unlink($path);
    $html = reportHtml('users', $admin);

    $row = collect(range(7, 12))->first(fn (int $row): bool => $sheet->getCell("A{$row}")->getValue() === 'Usuaria Segura');

    expect($row)->not->toBeNull()
        ->and($sheet->getCell("H{$row}")->getValue())->toBe('Sí');

    foreach ([$user->password, $user->two_factor_secret, $user->two_factor_recovery_codes, 'remember-me-token-123'] as $secret) {
        expect($text)->not->toContain($secret)
            ->and($html)->not->toContain($secret);
    }
});

test('the connections report never contains credentials', function () {
    $admin = User::factory()->administrator()->create();
    $connection = Connection::factory()->create(['phone_number_id' => '109876543210']);

    $path = renderReport('connections', $admin, ExportFormat::Xlsx);
    $text = reportWorkbookText($path);
    @unlink($path);
    $html = reportHtml('connections', $admin);

    expect($text)->toContain('109876543210')
        ->and($html)->toContain('109876543210');

    foreach ([$connection->access_token, $connection->app_secret, $connection->webhook_verify_token] as $secret) {
        expect($text)->not->toContain($secret)
            ->and($html)->not->toContain($secret);
    }
});

test('the roles report includes the permission matrix in both formats', function () {
    $admin = User::factory()->administrator()->create();

    $path = renderReport('roles', $admin, ExportFormat::Xlsx);
    $workbook = reportWorkbook($path);
    @unlink($path);
    $matrix = $workbook->getSheetByName('Matriz de permisos');

    expect($workbook->getSheetNames())->toContain('Matriz de permisos')
        ->and($matrix->getCell('A6')->getValue())->toBe('Módulo')
        ->and($matrix->getCell('D6')->getValue())->toBe('Superadministrador')
        ->and($matrix->getCell('D7')->getValue())->toBe('✓');

    expect(reportHtml('roles', $admin))->toContain('Matriz de permisos')->toContain('Reservado');
});

test('audit exports require audit.export and never reveal restricted entries to administrators', function () {
    $admin = User::factory()->administrator()->create();
    $superAdministrator = User::factory()->superAdministrator()->create();
    AuditLog::query()->create(['user_id' => $admin->id, 'actor_name' => 'Admin', 'action' => AuditAction::TagCreated, 'auditable_label' => 'Visible', 'restricted' => false, 'created_at' => now()]);
    AuditLog::query()->create(['user_id' => $superAdministrator->id, 'actor_name' => 'Raíz', 'action' => AuditAction::TagCreated, 'auditable_label' => 'Secreto raíz', 'restricted' => true, 'properties' => ['password' => 'p4ss'], 'created_at' => now()]);
    $auditOnly = reportUser([Permission::ViewAuditLog, Permission::ExportAuditLog]);

    $this->actingAs(reportUser([Permission::ViewAuditLog, Permission::ExportReports]))->post(route('exports.generate', ['audit', 'xlsx']))->assertForbidden();
    $this->actingAs($auditOnly)->post(route('exports.generate', ['audit', 'xlsx']))->assertOk();

    expect(reportHtml('audit', $admin))->toContain('Visible')->not->toContain('Secreto raíz')
        ->and(reportHtml('audit', $superAdministrator))->toContain('Secreto raíz')->not->toContain('p4ss');
});

test('large reports are queued and generated by the job', function () {
    Storage::fake('local');
    config(['exports.formats.xlsx.sync_rows' => 1]);
    $actor = reportUser([Permission::ViewContacts, Permission::ExportReports]);
    Contact::factory()->count(3)->create();

    $this->actingAs($actor)->from(route('contacts.index'))->post(route('exports.generate', ['contacts', 'xlsx']))->assertRedirect(route('contacts.index'));

    $transfer = DataTransfer::query()->sole();

    expect($transfer->status)->toBe(DataTransferStatus::Completed)
        ->and($transfer->rows_total)->toBe(3);
    Storage::disk('local')->assertExists($transfer->path);
});

test('a queued dossier fails when the conversation is no longer reachable', function () {
    Storage::fake('local');
    $agent = reportUser([Permission::ViewConversations, Permission::ExportReports], DataScope::Assigned);
    $conversation = Conversation::factory()->assignedTo(User::factory()->agent()->create())->create();
    $transfer = DataTransfer::factory()->for($agent)->create(['report' => 'conversation-dossier', 'filters' => ['conversation' => $conversation->id]]);

    GenerateReport::dispatchSync($transfer->id);

    expect($transfer->refresh()->status)->toBe(DataTransferStatus::Failed)
        ->and($transfer->path)->toBeNull();
});

test('the export menu flag follows the export permissions', function () {
    $this->actingAs(reportUser([Permission::ViewTags]))->get(route('tags.index'))
        ->assertInertia(fn ($page) => $page->where('canExport', false));
    $this->actingAs(reportUser([Permission::ViewTags, Permission::ExportReports]))->get(route('tags.index'))
        ->assertInertia(fn ($page) => $page->where('canExport', true));
    $this->actingAs(reportUser([Permission::ViewAuditLog, Permission::ExportAuditLog]))->get(route('admin.audit.index'))
        ->assertInertia(fn ($page) => $page->where('canExport', true));
});
