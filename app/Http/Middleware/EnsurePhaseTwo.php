<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints of the phase-two service rules (readiness, department and
 * category rules): while FEATURE_PHASE_TWO is off they do not exist (404),
 * whatever the interface shows.
 */
class EnsurePhaseTwo
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::phaseTwo(), 404);

        return $next($request);
    }
}
