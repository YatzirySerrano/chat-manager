<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Connections\ManageAppSubscription;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Meta\MetaGraphException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

/**
 * Connection diagnostics: check whether the Meta app is subscribed to the
 * WhatsApp Business Account webhooks, and subscribe it.
 */
class ConnectionSubscriptionController extends Controller
{
    private const ATTEMPTS_PER_MINUTE = 5;

    public function check(Request $request, Connection $connection, ManageAppSubscription $subscription): RedirectResponse
    {
        Gate::authorize('verify', $connection);

        if ($this->throttled($request, $connection)) {
            return back();
        }

        try {
            $result = $subscription->check($connection, $request->user());
        } catch (MetaGraphException $exception) {
            Inertia::flash('toast', ['type' => $exception->rejected ? 'error' : 'warning', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => $result['subscribed'] ? 'success' : 'warning', 'message' => $result['message']]);

        return back();
    }

    public function store(Request $request, Connection $connection, ManageAppSubscription $subscription): RedirectResponse
    {
        Gate::authorize('verify', $connection);

        if ($this->throttled($request, $connection)) {
            return back();
        }

        try {
            $subscription->subscribe($connection, $request->user());
        } catch (MetaGraphException $exception) {
            Inertia::flash('toast', ['type' => $exception->rejected ? 'error' : 'warning', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Meta confirmó la suscripción de la app a los webhooks de la cuenta de WhatsApp Business.']);

        return back();
    }

    private function throttled(Request $request, Connection $connection): bool
    {
        $key = "connection-subscription:{$request->user()->id}:{$connection->id}";

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($key).' segundos.']);

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
