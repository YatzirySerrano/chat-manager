<?php

namespace App\Http\Middleware\Api;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "api.ability:{ApiAbility value}": the authenticated token must hold the
 * ability, or the request is a 403 "forbidden". It runs after api.token.
 */
class EnsureApiTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $required = ApiAbility::tryFrom($ability) ?? throw new InvalidArgumentException("Capacidad de API desconocida: {$ability}.");
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        if (! $token instanceof ApiToken) {
            throw new AuthenticationException;
        }

        if (! $token->allows($required)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
