<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Enums\WebChatIdentityMode;
use App\Exceptions\ConversationConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\StartVisitorSessionRequest;
use App\Http\Requests\WebChat\VisitorIdentityRules;
use App\Http\Resources\WebChat\VisitorSessionResource;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use App\Services\WebChat\FrameTicket;
use App\Services\WebChat\VisitorIdentity;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Visitor sessions of the web chat widget (called only from the widget
 * iframe, same origin): start one when the widget loads, read it, and end
 * it. A session starts only with a valid frame ticket (proof that an
 * authorized host page loaded the frame) on an active, configured
 * connection; a widget that requires identification also needs a valid
 * signed identity.
 */
class VisitorSessionController extends Controller
{
    public function __construct(private VisitorSessions $sessions) {}

    public function store(StartVisitorSessionRequest $request, FrameTicket $tickets, VisitorIdentity $identities): JsonResponse
    {
        $widget = $request->widget();
        $connection = $widget->connection;
        $connection->setRelation('webChatWidget', $widget);

        $origin = $tickets->verify($widget, (string) $request->validated('ticket'));

        if ($origin === null) {
            throw new AccessDeniedHttpException;
        }

        if (! $connection->is_active || ! $connection->isConfigured()) {
            throw new ConversationConflict('widget_unavailable');
        }

        $this->countWidgetSession($widget);

        $identity = null;

        if ($widget->identity_mode !== WebChatIdentityMode::Anonymous && $request->has('identity')) {
            $identity = $identities->verify($widget, VisitorIdentityRules::payload((array) $request->validated('identity')));

            if ($identity === null) {
                throw new AuthenticationException;
            }
        }

        if ($widget->identity_mode === WebChatIdentityMode::Required && $identity === null) {
            throw new AuthenticationException;
        }

        /** @var array{name?: string|null, email?: string|null}|null $profile */
        $profile = $request->validated('profile');

        ['session' => $session, 'token' => $token] = $this->sessions->start(
            $widget,
            $origin,
            $request->ip(),
            $request->userAgent(),
            $profile,
            $identity,
            $request->validated('landing_url'),
        );

        $session->setRelation('connection', $connection);
        $request->attributes->set('webchat_session', $session);

        return (new VisitorSessionResource($session))
            ->additional(['token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request): VisitorSessionResource
    {
        return new VisitorSessionResource($this->session($request));
    }

    public function destroy(Request $request): Response
    {
        $this->sessions->revoke($this->session($request), 'visitor_closed');

        return response()->noContent();
    }

    /**
     * Per-widget cap on new sessions, shared by every address. It only
     * counts requests that proved a valid frame ticket, so junk requests
     * from whoever knows the public key can never exhaust it.
     *
     * @throws ThrottleRequestsException
     */
    private function countWidgetSession(WebChatWidget $widget): void
    {
        $key = 'webchat-sessions-widget:'.$widget->public_key;
        $maxAttempts = max(1, (int) config('webchat.limits.sessions_per_widget_per_minute', 300));

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function session(Request $request): WebChatSession
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');

        return $session;
    }
}
