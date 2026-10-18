<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the operational overview. Metrics are deferred so the page renders
     * immediately and the figures load right after.
     */
    public function __invoke(Request $request, DashboardMetrics $metrics): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'overview' => Inertia::defer(fn () => $metrics->overviewFor($user)),
        ]);
    }
}
