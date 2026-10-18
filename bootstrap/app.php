<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

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
