<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the operational overview. Metrics are deferred so the page renders
     * immediately and the figures load right after.
     *
     * The page is every user's landing page after signing in, so it always
     * renders; without dashboard.view no metric is computed nor sent, not even
     * through a partial reload asking for "overview".
     */
    public function __invoke(Request $request, DashboardMetrics $metrics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $canViewMetrics = $user->holdsPermission(Permission::ViewDashboard);

        return Inertia::render('Dashboard', [
            'canViewMetrics' => $canViewMetrics,
            'overview' => $canViewMetrics ? Inertia::defer(fn () => $metrics->overviewFor($user)) : null,
        ]);
    }
}
