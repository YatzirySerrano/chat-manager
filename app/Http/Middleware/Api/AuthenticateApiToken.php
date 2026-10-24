<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiToken;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the integration API (Bearer cm_live_{public_id}_{secret}).
 * Any failure (no header, another format such as a visitor token, unknown,
 * revoked or expired token, IP outside the allowlist) is the same 401 with
 * WWW-Authenticate: Bearer and no reason. The token is the principal: it is
 * left in the "api_token" request attribute, and $request->user() stays
 * null. last_used_at is written at most once per minute.
 *
 * Implementing AuthenticatesRequests places it before the throttle in the
 * framework's middleware priority, so the named limiters count per token.
 */
class AuthenticateApiToken implements AuthenticatesRequests
{
    public const ATTRIBUTE = 'api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || ! str_starts_with($plain, ApiToken::TOKEN_PREFIX)) {
            throw new AuthenticationException;
        }

        $token = ApiToken::findByToken($plain);

        if ($token === null || ! $token->isUsable() || ! $token->allowsIp((string) $request->ip())) {
            throw new AuthenticationException;
        }

        if ($token->last_used_at === null || $token->last_used_at->lessThan(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }
}
