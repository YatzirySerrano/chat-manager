<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\AnalyticsScope;
use App\Services\Analytics\OperationalAnalytics;
use App\Services\Exports\ReportExporter;
use App\Services\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Labels of the period presets, in the order they are offered.
     *
     * @var array<string, string>
     */
    public const PRESET_LABELS = [
        'today' => 'Hoy',
        'yesterday' => 'Ayer',
        '7d' => 'Últimos 7 días',
        '30d' => 'Últimos 30 días',
        '90d' => 'Últimos 90 días',
        'month' => 'Este mes',
        'previous_month' => 'Mes anterior',
        'custom' => 'Rango personalizado',
    ];

    /**
     * Operational reports of the viewer's reach. The figures are deferred so
     * the filters render at once; filters live in the query string, and
     * values outside the viewer's reach are dropped (never applied nor
     * echoed back).
     */
    public function index(Request $request, OperationalAnalytics $analytics, AnalyticsScope $scope, PlatformSettings $settings, ReportExporter $exporter): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->holdsPermission(Permission::ViewReports), 403);

        $validator = Validator::make($request->query(), AnalyticsFilters::rules());
        $invalid = $validator->fails();
        $filters = AnalyticsFilters::from($invalid ? [] : $validator->validated(), $user, $scope, (string) $settings->get('general.timezone'));

        return Inertia::render('reports/Index', [
            'filters' => $filters->toQuery(),
            'period' => $filters->period->toArray(),
            'invalidFilters' => $invalid ? $validator->errors()->first() : null,
            'presets' => array_map(
                fn (string $value): array => ['value' => $value, 'label' => self::PRESET_LABELS[$value]],
                AnalyticsPeriod::PRESETS,
            ),
            'maxDays' => AnalyticsPeriod::MAX_DAYS,
            'scope' => $scope->label($user),
            'options' => [
                'departments' => $scope->departmentOptions($user),
                'agents' => $scope->agentOptions($user),
                'connections' => $scope->connectionOptions($user),
            ],
            'analytics' => Inertia::defer(fn () => $analytics->report($user, $filters)),
            'canExport' => $exporter->allowsReport('analytics', $user),
        ]);
    }
}
