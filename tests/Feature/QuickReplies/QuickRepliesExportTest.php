<?php

use App\Enums\DataScope;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A user of the given scope holding the given permissions (plus exporting).
 *
 * @param  list<Permission>  $permissions
 * @param  list<Department>  $departments
 */
function quickReplyExporter(array $permissions, DataScope $scope = DataScope::Global, array $departments = []): User
{
    $role = Role::factory()->scope($scope)->withPermissions([...$permissions, Permission::ExportReports])->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create();
}

/**
 * An organization administrator that manages corporate replies, has
 * personal ones and supervises those of other users.
 */
function quickReplySupervisor(): User
{
    return quickReplyExporter([
        Permission::UseQuickReplies,
        Permission::ManageQuickReplies,
        Permission::CreatePersonalQuickReplies,
        Permission::ViewPersonalItems,
    ]);
}

/**
 * One corporate reply, two personal replies of the actor and two of a
 * colleague: the mixed set of the reported bug.
 */
function seedMixedQuickReplies(User $actor): User
{
    $colleague = User::factory()->agent()->create(['name' => 'Beto Colega']);

    QuickReply::factory()->create(['shortcut' => 'corporativa', 'title' => 'Bienvenida']);
    QuickReply::factory()->personal($actor)->create(['shortcut' => 'mia-uno']);
    QuickReply::factory()->personal($actor)->create(['shortcut' => 'mia-dos']);
    QuickReply::factory()->personal($colleague)->create(['shortcut' => 'colega-uno']);
    QuickReply::factory()->personal($colleague)->create(['shortcut' => 'colega-dos']);

    return $colleague;
}

/**
 * Data rows (below the header of row 6) of the quick replies sheet.
 *
 * @param  array<string, mixed>  $input
 * @return array{header: array<mixed>, rows: list<array<mixed>>}
 */
function quickRepliesWorkbookRows(User $actor, array $input = []): array
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('quick-replies');
    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $exporter->render($definition, $exporter->prepare($definition, $actor, $input), ExportFormat::Xlsx, $path);

    $sheet = IOFactory::createReader('Xlsx')->load($path)->getSheetByName('Respuestas rápidas');
    @unlink($path);

    $columns = count($definition->columns());
    $lastColumn = chr(ord('A') + $columns - 1);
    $header = $sheet->rangeToArray("A6:{$lastColumn}6")[0];
    $rows = [];

    for ($row = 7; $sheet->getCell("A{$row}")->getValue() !== null; $row++) {
        $rows[] = $sheet->rangeToArray("A{$row}:{$lastColumn}{$row}")[0];
    }

    return ['header' => $header, 'rows' => $rows];
}

/**
 * @param  array<string, mixed>  $input
 */
function quickRepliesPdfHtml(User $actor, array $input = []): string
{
    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('quick-replies');

    return app(PdfRenderer::class)->html($definition, $exporter->prepare($definition, $actor, $input));
}

/**
 * @param  list<array<mixed>>  $rows
 * @return list<string>
 */
function exportedShortcuts(array $rows): array
{
    $shortcuts = array_map(fn (array $row): string => (string) $row[0], $rows);
    sort($shortcuts);

    return $shortcuts;
}

test('the general report of a supervising administrator includes every authorized reply in Excel and PDF', function (array $input) {
    $actor = quickReplySupervisor();
    seedMixedQuickReplies($actor);

    $workbook = quickRepliesWorkbookRows($actor, $input);
    $html = quickRepliesPdfHtml($actor, $input);

    expect(exportedShortcuts($workbook['rows']))->toBe(['/colega-dos', '/colega-uno', '/corporativa', '/mia-dos', '/mia-uno'])
        ->and($html)->toContain('Todas las respuestas a las que tienes acceso');

    foreach (['/corporativa', '/mia-uno', '/mia-dos', '/colega-uno', '/colega-dos'] as $shortcut) {
        expect($html)->toContain($shortcut);
    }
})->with([
    'without a segment' => [[]],
    'with the general segment' => [['segment' => 'all']],
]);

test('the report has the agreed columns with type, owner and department', function () {
    $actor = quickReplySupervisor();
    $colleague = seedMixedQuickReplies($actor);
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $colleague->departments()->attach($sales);
    QuickReply::factory()->forDepartments($sales)->create(['shortcut' => 'ventas']);

    $workbook = quickRepliesWorkbookRows($actor);
    $rows = collect($workbook['rows'])->keyBy(fn (array $row): string => (string) $row[0]);

    expect($workbook['header'])->toBe(['Atajo', 'Título', 'Contenido', 'Tipo', 'Propietario', 'Departamento', 'Estado', 'Fecha de creación'])
        ->and($rows->count())->toBe(6)
        ->and(array_slice($rows['/corporativa'], 3, 5))->toBe(['Corporativa', null, 'Todos los departamentos', 'Activa', $rows['/corporativa'][7]])
        ->and($rows['/ventas'][5])->toBe('Ventas')
        ->and(array_slice($rows['/mia-uno'], 3, 2))->toBe(['Personal', $actor->name])
        ->and(array_slice($rows['/colega-uno'], 3, 3))->toBe(['Personal', 'Beto Colega', 'Ventas'])
        ->and($rows['/corporativa'][7])->not->toBeNull();
});

test('a report of one tab says which filter was used and keeps only that tab', function (string $segment, string $label, array $expected) {
    $actor = quickReplySupervisor();
    seedMixedQuickReplies($actor);

    $workbook = quickRepliesWorkbookRows($actor, ['segment' => $segment]);

    expect(exportedShortcuts($workbook['rows']))->toBe($expected)
        ->and(quickRepliesPdfHtml($actor, ['segment' => $segment]))->toContain($label);
})->with([
    'corporate' => ['corporate', 'Tipo: Corporativas', ['/corporativa']],
    'mine' => ['mine', 'Tipo: Mis respuestas personales', ['/mia-dos', '/mia-uno']],
    'others' => ['others', 'Tipo: Respuestas personales de otros usuarios', ['/colega-dos', '/colega-uno']],
]);

test('without the supervision permission the general report leaves other users personal replies out', function () {
    $actor = quickReplyExporter([Permission::UseQuickReplies, Permission::ManageQuickReplies, Permission::CreatePersonalQuickReplies]);
    seedMixedQuickReplies($actor);

    $workbook = quickRepliesWorkbookRows($actor);

    expect(exportedShortcuts($workbook['rows']))->toBe(['/corporativa', '/mia-dos', '/mia-uno'])
        ->and(exportedShortcuts(quickRepliesWorkbookRows($actor, ['segment' => 'others'])['rows']))->toBe(['/corporativa']);
});

test('a department manager never sees the names of departments outside their scope in the report', function () {
    $own = Department::factory()->create(['name' => 'Soporte']);
    $other = Department::factory()->create(['name' => 'Dirección confidencial']);
    $actor = quickReplyExporter([Permission::ManageQuickReplies], DataScope::Departments, [$own]);
    QuickReply::factory()->forDepartments($own, $other)->create(['shortcut' => 'compartida']);
    QuickReply::factory()->forDepartments($other)->create(['shortcut' => 'ajena']);

    $workbook = quickRepliesWorkbookRows($actor);

    expect(exportedShortcuts($workbook['rows']))->toBe(['/compartida'])
        ->and($workbook['rows'][0][5])->toBe('Soporte')
        ->and(quickRepliesPdfHtml($actor))->not->toContain('Dirección confidencial');
});

test('the reports of one personal tab keep the department of the owner', function (string $segment, string $shortcut) {
    $actor = quickReplySupervisor();
    $colleague = seedMixedQuickReplies($actor);
    $sales = Department::factory()->create(['name' => 'Ventas']);
    $colleague->departments()->attach($sales);
    $actor->departments()->attach($sales);

    $rows = collect(quickRepliesWorkbookRows($actor, ['segment' => $segment])['rows'])->keyBy(fn (array $row): string => (string) $row[0]);

    expect($rows[$shortcut][5])->toBe('Ventas');
})->with([
    'others' => ['others', '/colega-uno'],
    'mine' => ['mine', '/mia-uno'],
]);
