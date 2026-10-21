<?php

use App\Exceptions\ConversationConflict;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Conversation;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Meta credentials must never be stored as old input in the session.
        $exceptions->dontFlash(['access_token', 'app_secret', 'webhook_verify_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A record outside the actor's scope must look exactly like one that
        // does not exist, so JSON 404 responses never carry the model name,
        // the id or any other detail that would tell them apart.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => 'Not Found'], 404);
        });

        // Two operations on the same data can deadlock in MariaDB; the loser of
        // a conversation operation gets a controlled conflict, not an error.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->route('conversation') instanceof Conversation
                || ! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                return null;
            }

            return (new ConversationConflict('Otra operación modificó esta conversación al mismo tiempo. Revisa su estado actual e inténtalo de nuevo.'))->render($request);
        });

        // Permissions can be revoked while a page is open. Instead of an error
        // screen, Inertia visits return to a page the user can still see, with
        // fresh props so the interface stops offering the revoked operations.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->header('X-Inertia') || $request->user() === null) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => 'No tienes permiso para realizar esta acción. Actualizamos tu vista con tus permisos vigentes.']);

            if ($request->isMethod('GET')) {
                return $request->routeIs('dashboard') ? null : redirect()->route('dashboard');
            }

            return back(fallback: route('dashboard'));
        });
    })->create();
