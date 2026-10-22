<?php

namespace App\Reports\Definitions;

use App\Enums\ConversationStatus;
use App\Enums\Permission;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesReports;
use App\Reports\Metric;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Services\DashboardMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Executive summary of the dashboard: the same figures the user sees on the
 * dashboard (DashboardMetrics, same scope and the same "not available"
 * rules), plus charts and a per-department breakdown computed only over
 * the conversations the user can see.
 *
 * @phpstan-import-type Overview from DashboardMetrics
 *
 * @extends ReportDefinition<Department>
 */
class DashboardReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * Overview computed once per export.
     *
     * @var array<int, Overview>
     */
    private array $overviews = [];

    public function __construct(private DashboardMetrics $dashboard) {}

    public function key(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Resumen ejecutivo';
    }

    public function subtitle(): ?string
    {
        return 'Indicadores del panel principal';
    }

    public function fileName(): string
    {
        return 'resumen-ejecutivo';
    }

    public function authorize(User $actor): bool
    {
        return $actor->holdsPermission(Permission::ViewDashboard);
    }

    public function rules(): array
    {
        return [];
    }

    public function describeFilters(ReportContext $context): array
    {
        return ['Alcance: '.match ($this->overview($context)['scope']) {
            'all' => 'toda la organización',
            'departments' => 'tus departamentos',
            default => 'tus conversaciones',
        }];
    }

    /**
     * Departments the user can see with their visible conversation load;
     * empty when the user cannot see departments or conversations.
     */
    public function query(ReportContext $context): Builder
    {
        $actor = $context->actor;
        $startOfToday = $context->generatedAt->startOfDay()->utc();

        return Department::query()
            ->visibleTo($actor)
            ->notDeleted()
            ->when(! $this->showsBreakdown($context), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->withCount([
                'conversations as pending_count' => fn (Builder $query) => $this->visible($query, $actor)->where('status', ConversationStatus::Pending),
                'conversations as attending_count' => fn (Builder $query) => $this->visible($query, $actor)->whereIn('status', [ConversationStatus::Open, ConversationStatus::OnHold]),
                'conversations as closed_today_count' => fn (Builder $query) => $this->visible($query, $actor)->where('status', ConversationStatus::Closed)->where('closed_at', '>=', $startOfToday),
            ])
            ->orderBy('name')
            ->orderBy('id');
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Departamento', width: 34),
            new Column('pending', 'Pendientes', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('attending', 'Activas', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
            new Column('closed_today', 'Cerradas hoy', ColumnType::Number, width: 14, total: ColumnTotal::Sum),
        ];
    }

    /**
     * @param  Department  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        return [
            'name' => $record->name,
            'pending' => (int) $record->getAttribute('pending_count'),
            'attending' => (int) $record->getAttribute('attending_count'),
            'closed_today' => (int) $record->getAttribute('closed_today_count'),
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $overview = $this->overview($context);
        $unavailable = $overview['messaging_connected'] ? 'Sin permiso para consultarlo' : 'Aún no hay un número de WhatsApp con tráfico confirmado';
        $conversations = $overview['conversations'];
        $average = $overview['average_first_response_seconds'];

        $metrics = [
            new Metric('Activas', $conversations['active'], $conversations['active'] === null ? $unavailable : 'En atención o en espera'),
            new Metric('Pendientes', $conversations['pending'], $conversations['pending'] === null ? $unavailable : 'Esperan ser aceptadas'),
            new Metric('Cerradas hoy', $conversations['closed_today'], $conversations['closed_today'] === null ? $unavailable : 'Desde el inicio del día'),
            new Metric(
                'Primera respuesta (prom.)',
                $average === null ? null : $this->duration($average),
                $average === null ? ($overview['messaging_connected'] ? 'Sin conversaciones respondidas en 30 días' : $unavailable) : 'Últimos 30 días',
            ),
        ];

        if ($overview['departments'] !== null) {
            $metrics[] = new Metric('Personas que atienden', $overview['agents']['registered'], 'Cuentas activas que pueden responder');
            $metrics[] = new Metric('Departamentos activos', $overview['departments']['active'], number_format($overview['departments']['total']).' en total');
        }

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        if ($this->overview($context)['conversations']['active'] === null) {
            return [];
        }

        $actor = $context->actor;
        $byStatus = Conversation::query()->visibleTo($actor)->notClosed()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $open = [ConversationStatus::Pending, ConversationStatus::Open, ConversationStatus::OnHold];
        $monthly = $this->monthlySeries(Conversation::query()->visibleTo($actor), $context);

        $charts = [
            new Chart(
                ChartType::Donut,
                'Conversaciones sin cerrar',
                array_map(fn (ConversationStatus $status): string => $status->label(), $open),
                [new ChartSeries('Conversaciones', array_map(fn (ConversationStatus $status): int => (int) ($byStatus[$status->value] ?? 0), $open))],
                'Estado actual de la atención',
            ),
            new Chart(
                ChartType::Line,
                'Conversaciones nuevas por mes',
                $monthly['labels'],
                [new ChartSeries('Conversaciones', $monthly['values'])],
                'Últimos 12 meses dentro de tu alcance',
            ),
        ];

        return $charts;
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Carga por departamento');
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    private function visible(Builder $query, User $actor): Builder
    {
        return $query->visibleTo($actor);
    }

    private function showsBreakdown(ReportContext $context): bool
    {
        $overview = $this->overview($context);

        return $overview['departments'] !== null && $overview['conversations']['active'] !== null;
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return "{$seconds} s";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min '.($seconds % 60).' s';
        }

        return intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min';
    }

    /**
     * @return Overview
     */
    private function overview(ReportContext $context): array
    {
        return $this->overviews[$context->actor->id] ??= $this->dashboard->overviewFor($context->actor);
    }
}
