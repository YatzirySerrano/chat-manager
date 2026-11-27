<?php

use App\Enums\ConversationOrigin;
use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\ExportFormat;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Reports\Rendering\PdfRenderer;
use App\Reports\ReportRegistry;
use App\Services\Exports\ReportExporter;
use App\Support\ProtectedAccounts;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
| «Resultados de solicitudes»: completed requests and their credit result
| (approved with the authorized amount, rejected, none) per collaborator,
| department and day, on /reports («Resultados») and exported.
*/

beforeEach(function () {
    // Wednesday 2026-10-21 12:00 local time.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
    $this->ana = User::factory()->agent()->inDepartments($this->sales)->create(['name' => 'Ana Ventas']);
    $this->carla = User::factory()->agent()->inDepartments($this->support)->create(['name' => 'Carla Soporte']);
});

/**
 * A request completed by the agent, with an optional result.
 *
 * @param  array<string, mixed>  $attributes
 */
function completedRequest(Department $department, User $agent, ?ConversationOutcome $outcome = null, ?string $amount = null, ?CarbonImmutable $at = null, array $attributes = []): Conversation
{
    $at ??= CarbonImmutable::now()->subDay();

    return Conversation::factory()->inDepartment($department)->assignedTo($agent)->create()->forceFill([
        'status' => ConversationStatus::Closed,
        'closed_at' => $at,
        'closed_by_user_id' => $agent->id,
        'completed_at' => $at,
        'outcome' => $outcome,
        'outcome_amount' => $amount,
        'outcome_at' => $outcome === null ? null : $at,
        'outcome_by_user_id' => $outcome === null ? null : $agent->id,
        ...$attributes,
    ]);
}

function outcomesUser(array $permissions, DataScope $scope, array $departments = []): User
{
    return User::factory()->withRole(Role::factory()->scope($scope)->withPermissions($permissions)->create())->inDepartments($departments)->create();
}

function seedOutcomes(): void
{
    $test = test();
    completedRequest($test->sales, $test->ana, ConversationOutcome::Approved, '3000')->save();
    completedRequest($test->sales, $test->ana, ConversationOutcome::Approved, '2000')->save();
    completedRequest($test->sales, $test->ana, ConversationOutcome::Rejected)->save();
    completedRequest($test->support, $test->carla, ConversationOutcome::Approved, '5000.50')->save();
    completedRequest($test->support, $test->carla, ConversationOutcome::Approved)->save();
    completedRequest($test->support, $test->carla)->save();
    // Outside the cohort: imported history, completed before the period, reopened (no longer completed).
    completedRequest($test->sales, $test->ana, ConversationOutcome::Approved, '9999', attributes: ['origin' => ConversationOrigin::Whaticket])->save();
    completedRequest($test->sales, $test->ana, ConversationOutcome::Approved, '9999', CarbonImmutable::now()->subDays(40))->save();
    completedRequest($test->sales, $test->ana, ConversationOutcome::Approved, '9999', attributes: ['completed_at' => null])->save();
}

test('a result corrected later counts on the day it was recorded', function () {
    completedRequest($this->sales, $this->ana, ConversationOutcome::Approved, '1000', CarbonImmutable::now()->subDays(40), ['outcome_at' => CarbonImmutable::now()->subDay()])->save();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('outcomes', fn (Assert $reload) => $reload
            ->where('outcomes.totals.approved', 1)
            ->where('outcomes.totals.amount', 1000)));
});

test('a global user sees totals, people and departments of the period', function () {
    seedOutcomes();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->missing('outcomes')
            ->where('canExportOutcomes', true)
            ->reloadOnly('outcomes', fn (Assert $reload) => $reload
                ->where('outcomes.totals.completed', 6)
                ->where('outcomes.totals.approved', 4)
                ->where('outcomes.totals.rejected', 1)
                ->where('outcomes.totals.without', 1)
                ->where('outcomes.totals.approval_rate', 80)
                ->where('outcomes.totals.amount', 10000.5)
                ->where('outcomes.totals.with_amount', 3)
                ->where('outcomes.totals.average_amount', 3333.5)
                // Most authorized amount first.
                ->where('outcomes.by_agent.0.name', 'Carla Soporte')
                ->where('outcomes.by_agent.0.amount', 5000.5)
                ->where('outcomes.by_agent.0.without', 1)
                ->where('outcomes.by_agent.1.name', 'Ana Ventas')
                ->where('outcomes.by_agent.1.amount', 5000)
                ->where('outcomes.by_agent.1.approval_rate', 66.7)
                ->has('outcomes.by_department', 2)
                ->has('outcomes.trend.points', 30)
                ->where('outcomes.trend.points', fn ($points) => collect($points)->firstWhere('key', '2026-10-20')['completed'] === 6)));
});

test('a department scope only sees its departments and a personal scope only what it completed', function () {
    seedOutcomes();
    $supervisor = outcomesUser([Permission::ViewReports], DataScope::Departments, [$this->support]);

    $this->actingAs($supervisor)
        ->get(route('reports.index', ['department' => $this->sales->id]))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('outcomes', fn (Assert $reload) => $reload
            ->where('outcomes.totals.completed', 3)
            ->where('outcomes.totals.amount', 5000.5)
            ->has('outcomes.by_agent', 1)
            ->where('outcomes.by_agent.0.name', 'Carla Soporte')));

    $this->ana->roles->first()->givePermissionTo(Permission::ViewReports->value);

    $this->actingAs($this->ana->refresh())
        ->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('outcomes', fn (Assert $reload) => $reload
            ->where('outcomes.totals.completed', 3)
            ->where('outcomes.totals.approved', 2)
            ->has('outcomes.by_agent', 1)));
});

test('the agent filter keeps the requests that person completed', function () {
    seedOutcomes();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['agent' => $this->carla->id]))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('outcomes', fn (Assert $reload) => $reload
            ->where('outcomes.totals.completed', 3)
            ->where('outcomes.totals.rejected', 0)
            ->has('outcomes.by_agent', 1)));
});

test('protected accounts are not named to viewers without root authority', function () {
    $root = User::factory()->superAdministrator()->inDepartments($this->sales)->create(['name' => 'Raíz Secreta']);
    completedRequest($this->sales, $root, ConversationOutcome::Approved, '100')->save();

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index'))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('outcomes', fn (Assert $reload) => $reload
            ->where('outcomes.by_agent.0.id', null)
            ->where('outcomes.by_agent.0.name', ProtectedAccounts::SYSTEM_NAME)));
});

test('the export has the totals, the tables and the detail within the viewer reach', function () {
    seedOutcomes();
    $supervisor = outcomesUser([Permission::ViewReports, Permission::ExportReports], DataScope::Departments, [$this->sales]);

    $exporter = app(ReportExporter::class);
    $definition = app(ReportRegistry::class)->find('request-outcomes');
    $context = $exporter->prepare($definition, $supervisor, ['period' => '7d']);
    $path = $exporter->temporaryPath(ExportFormat::Xlsx);
    $written = $exporter->render($definition, $context, ExportFormat::Xlsx, $path);

    $workbook = IOFactory::createReader('Xlsx')->load($path);
    $text = '';

    foreach ($workbook->getAllSheets() as $sheet) {
        foreach ($sheet->toArray() as $row) {
            $text .= implode('|', array_map('strval', $row))."\n";
        }
    }

    @unlink($path);

    expect($written)->toBe(3)
        ->and($workbook->getSheetNames())->toContain('Resumen', 'Por colaborador', 'Por departamento', 'Por día')
        ->and($text)->toContain('Ana Ventas', 'Crédito aprobado', 'Rechazada')
        ->and($text)->not->toContain('Carla Soporte');

    $html = app(PdfRenderer::class)->html($definition, $context);

    expect($html)->toContain('<h1>Resultados de solicitudes</h1>')
        ->toContain('Por colaborador')
        ->not->toContain('Carla Soporte');
});

test('the export requires reports.view and reports.export', function () {
    $this->actingAs(outcomesUser([Permission::ViewReports], DataScope::Global))->post(route('exports.generate', ['request-outcomes', 'xlsx']))->assertForbidden();
    $this->actingAs(outcomesUser([Permission::ViewReports, Permission::ExportReports], DataScope::Global))->post(route('exports.generate', ['request-outcomes', 'xlsx']))->assertOk();
});
