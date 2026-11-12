<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WhatsAppWebState;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use App\Services\WhatsAppWeb\WhatsAppWebSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Linking and state of the WhatsApp Web (QR) session of a connection, for
 * the interface (contract §8). Every route answers the LinkState as JSON.
 *
 * Reading the state needs the permission to see the connection; the QR and
 * every action (link, refresh, disconnect, reconnect, logout) need the
 * permission to manage connections, and only exist for connections whose
 * provider is "whatsapp_web" (anything else is a 404). The three ways of
 * stopping are different on purpose:
 *
 * - disconnect: the session stops and the phone stays linked (or a link in
 *   progress is cancelled);
 * - logout: the session of the device is closed; a new QR is needed;
 * - deleting the connection (ConnectionController::destroy) removes the
 *   session and keeps every conversation.
 *
 * None of them deletes conversations or history. A service that does not
 * answer is a 503 with the reason, never an invented state.
 */
class WhatsAppWebSessionController extends Controller
{
    public function __construct(private WhatsAppWebSessions $sessions) {}

    public function show(Request $request, Connection $connection): JsonResponse
    {
        Gate::authorize('view', $connection);
        abort_unless($connection->isWhatsAppWeb(), 404);

        $state = $connection->webSessionState();

        // While a link or a recovery is in progress the service is asked
        // directly (at most once every two seconds per connection), so the
        // screen moves even if an event was delayed.
        if ($state !== null && ($state->isTransitional() || $request->boolean('fresh')) && ! $connection->isDeleted()
            && Cache::add('whatsapp-web:sync:'.$connection->id, 1, 2)) {
            $this->sessions->sync($connection);
        }

        return $this->state($request, $connection);
    }

    public function link(Request $request, Connection $connection): JsonResponse
    {
        return $this->run($request, $connection, fn (User $actor) => $this->sessions->link($connection, $actor));
    }

    public function refresh(Request $request, Connection $connection): JsonResponse
    {
        return $this->run($request, $connection, fn (User $actor) => $this->sessions->refresh($connection, $actor));
    }

    public function disconnect(Request $request, Connection $connection): JsonResponse
    {
        return $this->run($request, $connection, fn (User $actor) => $this->sessions->disconnect($connection, $actor));
    }

    public function reconnect(Request $request, Connection $connection): JsonResponse
    {
        return $this->run($request, $connection, fn (User $actor) => $this->sessions->reconnect($connection, $actor));
    }

    public function logout(Request $request, Connection $connection): JsonResponse
    {
        return $this->run($request, $connection, fn (User $actor) => $this->sessions->logout($connection, $actor));
    }

    /**
     * @param  callable(User): mixed  $action
     */
    private function run(Request $request, Connection $connection, callable $action): JsonResponse
    {
        Gate::authorize('manageWebSession', $connection);

        if (! $this->sessions->isEnabled()) {
            return response()->json(['message' => 'WhatsApp Web (QR) no está habilitado en este servidor.'], 503);
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $action($actor);
        } catch (ValidationException $exception) {
            // The service did not answer: say so instead of leaving a stale state on screen.
            if (! $this->sessions->serviceAvailable(fresh: true)) {
                return response()->json([
                    'message' => $exception->validator->errors()->first() ?: 'El servicio de WhatsApp Web no responde en este servidor.',
                    'link_state' => $this->sessions->linkState($connection->refresh(), withQr: false),
                ], 503);
            }

            throw $exception;
        }

        return $this->state($request, $connection->refresh());
    }

    private function state(Request $request, Connection $connection): JsonResponse
    {
        $canManage = $request->user()?->can('manageWebSession', $connection) ?? false;
        $state = $this->sessions->linkState($connection, withQr: $canManage);

        if (! $canManage) {
            $state['actions'] = array_map(fn (): bool => false, $state['actions']);
        }

        // A pending QR that expired without a new one from the service.
        if ($state['state'] === WhatsAppWebState::QrPending->value && $state['qr'] === null && $canManage) {
            $state['actions']['refresh'] = true;
        }

        return response()->json($state)->header('Cache-Control', 'private, no-store');
    }
}
