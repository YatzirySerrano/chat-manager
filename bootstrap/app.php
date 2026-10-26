<?php

use App\Exceptions\ConversationConflict;
use App\Http\Middleware\Api\ApiResponseHeaders;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Middleware\Api\EnsureApiTokenAbility;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleChannelAuthorization;
use App\Http\Middleware\ThrottleRequestsPerRoute;
use App\Http\Middleware\WebChat\AuthenticateVisitorSession;
use App\Models\Conversation;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Integration (Bearer cm_live_…) and web chat visitor (Bearer wvs_…)
        // APIs under /api/v1: the stateless "api" group, without session,
        // cookies, CSRF or CORS.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Real-time channel authorization (POST /broadcasting/auth): only signed
    // in, verified and active users (EnsureUserIsActive runs in "web"),
    // throttled so a reconnection storm cannot flood the application. Its
    // limiter survives the deadlocks of parallel authorizations of one user
    // on the database cache store (ThrottleChannelAuthorization).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'verified', 'throttle.channels:180,1']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // "throttle:attempts,minutes" counts per route, not one shared counter per user.
        $middleware->alias([
            'throttle' => ThrottleRequestsPerRoute::class,
            'throttle.channels' => ThrottleChannelAuthorization::class,
            'api.token' => AuthenticateApiToken::class,
            'api.ability' => EnsureApiTokenAbility::class,
            'webchat.session' => AuthenticateVisitorSession::class,
        ]);

        // Every response (pages, webhooks, health): correlation id and browser hardening headers.
        $middleware->append([AssignRequestId::class, SecurityHeaders::class]);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // /api/v1: JSON clients, Cache-Control: no-store on every response.
        $middleware->api(append: [ApiResponseHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Meta credentials must never be stored as old input in the session.
        $exceptions->dontFlash(['access_token', 'app_secret', 'webhook_verify_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every /api/v1 error has the same body, {message, code} (plus
        // "errors" on 422), whatever raised it: see ApiResponseHeaders.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            return ApiResponseHeaders::applies($request) ? ApiResponseHeaders::errorResponse($response, $e) : $response;
        });

        // A record outside the actor's scope must look exactly like one that
        // does not exist, so JSON 404 responses never carry the model name,
        // the id or any other detail that would tell them apart.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 404 || (! $request->is('api/*') && ! $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => 'No se encontró la página o el recurso solicitado.'], 404);
        });

        // A request larger than PHP's post_max_size arrives without its
        // fields (the file never reaches validation): explain it in Spanish.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $limit = trim((string) ini_get('post_max_size'));

            return response()->json([
                'message' => 'El archivo es demasiado grande para que el servidor lo reciba'.($limit !== '' ? " (límite actual: {$limit}B)" : '').'. Intenta con un archivo más pequeño o pide a un administrador que amplíe el límite de subida.',
            ], 413);
        });

        // Download links sent to customers: an altered or expired link gets
        // a plain explanation instead of a technical error page.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->routeIs('attachments.link')) {
                return null;
            }

            return response('El enlace de descarga no es válido o ya venció. Pide a quien te atendió que te envíe el archivo de nuevo.', 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        });

        // Two operations on the same data can deadlock in MariaDB; the loser of
        // a conversation operation gets a controlled conflict, not an error,
        // so it is logged as a warning instead of an application error.
        $exceptions->report(function (QueryException $e) {
            $request = request();

            if (! $request->route('conversation') instanceof Conversation
                || ! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                return null;
            }

            Log::warning('Operación de conversación interrumpida por concurrencia en la base de datos.', [
                'conversation_id' => $request->route('conversation')->getKey(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'route' => $request->route()?->getName(),
                'sql_state' => $e->getCode(),
            ]);

            return false;
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->route('conversation') instanceof Conversation
                || ! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                return null;
            }

            return (new ConversationConflict('Otra operación modificó esta conversación al mismo tiempo. Revisa su estado actual e inténtalo de nuevo.'))->render($request);
        });

        // A real authorization failure (403) on something the user can see:
        // permissions can be revoked while a page is open. Instead of an error
        // screen, Inertia visits return to a page the user can still see, with
        // fresh props so the interface stops offering the revoked operations.
        // A state that changed meanwhile is not a 403 but a 409
        // (ConversationStateConflict), and something out of reach is a 404.
        // JSON clients receive {message} with the same text.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->header('X-Inertia') || $request->user() === null) {
                return null;
            }

            // Imported (read-only) history explains itself; any other 403 gets the plain text.
            $message = $e->getMessage() === ConversationLifecycle::IMPORTED_MESSAGE ? ConversationLifecycle::IMPORTED_MESSAGE : ConversationLifecycle::FORBIDDEN_MESSAGE;

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            if ($request->isMethod('GET')) {
                return $request->routeIs('dashboard') ? null : redirect()->route('dashboard');
            }

            return back(fallback: route('dashboard'));
        });
    })->create();
