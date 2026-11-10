<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\AnalyticsScope;
use App\Services\DashboardMetrics;
use App\Services\Exports\ReportExporter;
use App\Support\LandingPage;
use Illuminate\Http\RedirectResponse;
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
     * immediately and the figures load right after; the charts are a second
     * deferred group ("charts") so they never delay the headline figures.
     *
     * Fortify sends everyone here after signing in, but only dashboard.view
     * opens the panel: anyone else is redirected to the first module they
     * may use (LandingPage), before any metric is computed, also for partial
     * reloads asking for "overview" or "charts". Invalid filters are
     * ignored (the defaults apply) instead of breaking the landing page.
     */
    public function __invoke(Request $request, DashboardMetrics $metrics, AnalyticsScope $scope, ReportExporter $exporter, LandingPage $landing): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->holdsPermission(Permission::ViewDashboard)) {
            return redirect()->to($landing->urlFor($user));
        }

        $validator = Validator::make($request->query(), [
            ...AnalyticsFilters::rules(),
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ]);
        $filters = $metrics->filters($user, $validator->fails() ? [] : $validator->validated());

        return Inertia::render('Dashboard', [
            'canViewMetrics' => true,
            'overview' => Inertia::defer(fn () => $metrics->overviewFor($user, $filters)),
            // Own group: the charts never delay the headline figures.
            'charts' => Inertia::defer(fn () => $metrics->chartsFor($user, $filters), 'charts'),
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
