<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Connections\RegisterPhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\RegisterPhoneNumberRequest;
use App\Models\Connection;
use App\Services\Meta\MetaGraphException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Registers the WhatsApp number of a connection with the Cloud API
 * (POST /{phone-number-id}/register), the step Meta requires before the
 * number can send or receive through the API.
 */
class ConnectionRegistrationController extends Controller
{
    /**
     * Meta locks the registration after a few wrong PINs: few attempts per
     * user and connection, over a long window.
     */
    private const ATTEMPTS = 3;

    private const DECAY_SECONDS = 600;

    public function store(RegisterPhoneNumberRequest $request, Connection $connection, RegisterPhoneNumber $register): RedirectResponse
    {
        // From here on the PIN lives only in this variable: errors raised
        // below redirect back with the request input flashed to the session.
        $pin = $request->pin();
        $request->forgetPin();

        $key = "connection-registration:{$request->user()->id}:{$connection->id}";

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([
                'pin' => "Demasiados intentos de registro. Espera {$minutes} ".($minutes === 1 ? 'minuto' : 'minutos').' antes de intentarlo de nuevo.',
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        try {
            $register->handle($connection, $pin, $request->user());
        } catch (MetaGraphException $exception) {
            // Shown inside the dialog, next to the PIN field.
            throw ValidationException::withMessages(['pin' => $exception->getMessage()]);
        }

        RateLimiter::clear($key);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Meta confirmó el registro del número en la API de WhatsApp.']);

        return back();
    }
}
