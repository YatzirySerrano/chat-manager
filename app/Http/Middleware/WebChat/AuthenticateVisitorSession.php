<?php

namespace App\Http\Middleware\WebChat;

use App\Models\WebChatSession;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the web chat visitor surface (/api/v1/widget/session…) with
 * the visitor's bearer token ('wvs_…'). Anything else (no token, an
 * integration token, a malformed, unknown, expired or revoked one, a
 * session whose connection or widget is gone, or one opened from a host
 * origin the widget no longer allows) is the same 401, which never says
 * why: removing a site from the allowed origins ends its open sessions. The web session and its cookies are never consulted.
 *
 * The session's expiry slides with use, written at most once per minute so
 * polling never writes on every request. The session (with its connection
 * and widget loaded) is put on the request as 'webchat_session', and the
 * widget as 'webchat_widget'.
 *
 * It implements AuthenticatesRequests (a marker) so the framework's
 * middleware priority runs it BEFORE the named throttles: otherwise the
 * per-session limiters find no session yet and fall back to the IP, and
 * every visitor behind the same address shares one session's limit.
 */
class AuthenticateVisitorSession implements AuthenticatesRequests
{
    private const TOUCH_INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $session = is_string($token) && str_starts_with($token, WebChatSession::TOKEN_PREFIX)
            ? WebChatSession::findByToken($token)
            : null;

        $session?->loadMissing('connection.webChatWidget');
        $widget = $session?->connection?->webChatWidget;

        if ($session === null || ! $session->isUsable() || $session->connection === null || $session->connection->isDeleted() || $widget === null || ! $widget->allowsOrigin($session->origin)) {
            throw new AuthenticationException;
        }

        if ($session->last_seen_at === null || $session->last_seen_at->lessThan(now()->subSeconds(self::TOUCH_INTERVAL_SECONDS))) {
            $session->slideExpiry();
        }

        $request->attributes->set('webchat_session', $session);
        $request->attributes->set('webchat_widget', $widget);

        return $next($request);
    }
}
