<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Connections\VerifyConnectionCredentials;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class ConnectionVerificationController extends Controller
{
    /**
     * Verification attempts allowed per user and connection each minute; it
     * protects the Meta app from rate limits caused by repeated clicks.
     */
    private const ATTEMPTS_PER_MINUTE = 5;

    /**
     * Check the saved credentials of a connection against Meta.
     */
    public function store(Request $request, Connection $connection, VerifyConnectionCredentials $verifyCredentials): RedirectResponse
    {
        Gate::authorize('verify', $connection);

        $key = "connection-verification:{$request->user()->id}:{$connection->id}";

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($key);

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => "Demasiados intentos de verificación. Espera {$seconds} segundos antes de intentarlo de nuevo.",
            ]);

            return back();
        }

        RateLimiter::hit($key, 60);

        $verification = $verifyCredentials->handle($connection, $request->user());

        Inertia::flash('toast', ['type' => $verification->tone(), 'message' => $verification->message]);

        return back();
    }
}
