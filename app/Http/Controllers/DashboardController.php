<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\DashboardMetrics;
use App\Services\Exports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Presets offered by the dashboard (the reports module offers them all).
     *
     * @var list<string>
     */
    public const PERIODS = ['today', '7d', '30d'];

    /**
     * Show the operational overview. Metrics are deferred so the page renders
     * immediately and the figures load right after.
     *
     * The page is every user's landing page after signing in, so it always
     * renders; without dashboard.view no metric is computed nor sent, not even
     * through a partial reload asking for "overview". Invalid filters are
     * ignored (the defaults apply) instead of breaking the landing page.
     */
    public function __invoke(Request $request, DashboardMetrics $metrics, AnalyticsScope $scope, ReportExporter $exporter): Response
    {
        /** @var User $user */
        $user = $request->user();
        $canViewMetrics = $user->holdsPermission(Permission::ViewDashboard);

        if (! $canViewMetrics) {
            return Inertia::render('Dashboard', [
                'canViewMetrics' => false,
                'overview' => null,
                'canExport' => $exporter->allowsReport('dashboard', $user),
            ]);
        }

        $validator = Validator::make($request->query(), [
            ...AnalyticsFilters::rules(),
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ]);
        $filters = $metrics->filters($user, $validator->fails() ? [] : $validator->validated());

        return Inertia::render('Dashboard', [
            'canViewMetrics' => true,
            'overview' => Inertia::defer(fn () => $metrics->overviewFor($user, $filters)),
            'filters' => $filters->toQuery(),
            'period' => $filters->period->toArray(),
            'periods' => self::PERIODS,
            'options' => [
                'departments' => $scope->departmentOptions($user),
                'agents' => $scope->agentOptions($user),
                'connections' => $scope->connectionOptions($user),
            ],
            'canViewReports' => $user->holdsPermission(Permission::ViewReports),
            'canExport' => $exporter->allowsReport('dashboard', $user),
        ]);
    }
}
