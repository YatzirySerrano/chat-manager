<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Telegram\TelegramBots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Volver a conectar webhook» of a Telegram bot connection: registers the
 * webhook again with a new secret (TelegramBots::connect). Only for people
 * who can edit the connection, and never for a deleted one.
 */
class TelegramBotController extends Controller
{
    public function reconnect(Connection $connection, TelegramBots $bots): RedirectResponse
    {
        Gate::authorize('update', $connection);
        abort_unless($connection->isTelegram() && ! $connection->isDeleted(), 404);

        $error = $bots->connect($connection);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => 'Webhook conectado. Telegram ya envía los mensajes aquí.']
            : ['type' => 'error', 'message' => $error]);

        return back();
    }
}
