<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;

/**
 * The "throttle:attempts,minutes" middleware with one counter per route.
 *
 * Laravel's default signature is only the user (or IP), so every throttled
 * route shares a single counter per minute: the notification bell polling
 * its summary or the real-time channel authorizations used up the allowance
 * of "throttle:10,1" on the contact import or the exports, and those answered
 * 429 to a user who had not used them at all. Each route now keeps its own
 * limit. Named limiters (throttle:name) keep their own keys and are unchanged.
 */
class ThrottleRequestsPerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        $signature = parent::resolveRequestSignature($request);
        $route = $request->route();

        if (! $route instanceof Route) {
            return $signature;
        }

        return sha1($signature.'|'.implode(',', $route->methods()).'|'.($route->getName() ?? $route->uri()));
    }
}
