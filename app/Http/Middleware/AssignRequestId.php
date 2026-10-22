<?php

namespace App\Http\Middleware;

use App\Support\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id of the request: reuses the X-Request-Id set by the
 * reverse proxy (Nginx $request_id) when it is well formed, otherwise
 * generates one. It is added to every log entry (and to the jobs dispatched
 * by the request) and returned to the client so a support report can be
 * matched with the logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        LogContext::add(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
