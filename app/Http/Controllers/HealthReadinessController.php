<?php

namespace App\Http\Controllers;

use App\Services\Operations\HealthStatus;
use App\Services\Operations\SystemHealth;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Public readiness probe for load balancers and uptime monitors:
 * {"status":"ok"} with 200, or {"status":"fail"} with 503 when any check
 * fails. It never tells which check failed nor any internal detail (those
 * are on Administración > Sistema). /up remains the plain liveness probe.
 *
 * The verdict is reused for operations.readiness.cache_seconds, so public
 * traffic never multiplies the health queries; when the cache itself is
 * unavailable the checks run directly (and the cache check fails).
 */
class HealthReadinessController extends Controller
{
    private const CACHE_KEY = 'operations:readiness-verdict';

    public function __invoke(SystemHealth $health, Cache $cache): JsonResponse
    {
        $seconds = max(0, (int) config('operations.readiness.cache_seconds', 10));
        $ready = null;

        if ($seconds > 0) {
            try {
                $ready = $cache->remember(self::CACHE_KEY, $seconds, fn (): bool => $this->evaluate($health));
            } catch (Throwable) {
                $ready = null;
            }
        }

        $ready = is_bool($ready) ? $ready : $this->evaluate($health);

        return response()
            ->json(['status' => $ready ? 'ok' : 'fail'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function evaluate(SystemHealth $health): bool
    {
        try {
            return $health->overall() !== HealthStatus::Fail;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
