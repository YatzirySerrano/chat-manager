<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Push\Vapid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

/**
 * Web Push subscriptions of the authenticated user's browsers, managed from
 * the notification preferences page. A browser belongs to whoever
 * registered it last (upsert by endpoint hash), and every user only ever
 * sees or deletes their own.
 */
class PushSubscriptionController extends Controller
{
    /**
     * From the preferences page (an Inertia visit with a toast), or silently
     * from any page that subscribed this browser on its own (JSON, 204).
     */
    public function store(StorePushSubscriptionRequest $request): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $endpoint = $request->string('endpoint')->toString();

        PushSubscription::query()->upsert([[
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'user_agent' => mb_substr((string) PushSubscription::describeAgent($request->userAgent()), 0, 120) ?: null,
        ]], ['endpoint_hash'], ['user_id', 'user_agent', 'updated_at']);

        $stale = PushSubscription::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->skip(PushSubscription::MAX_PER_USER)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            PushSubscription::query()->whereKey($stale)->delete();
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notificaciones del navegador activadas. Te avisaremos aunque la plataforma no esté a la vista.']);

        return back();
    }

    /**
     * «Enviar notificación de prueba»: one push to every browser of the
     * user, through the same notifications queue as the real ones, so it
     * also proves the queue worker is running. The device shows the
     * platform's summary notice (there is no new notification to show).
     */
    public function test(Request $request, Vapid $vapid): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $vapid->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'El servidor no tiene configuradas las claves de notificaciones (VAPID). Pide a Sistemas que corra php artisan webpush:vapid.']);

            return back();
        }

        $ids = PushSubscription::query()->where('user_id', $user->id)->pluck('id');

        if ($ids->isEmpty()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Primero activa las notificaciones en este navegador.']);

            return back();
        }

        foreach ($ids as $id) {
            SendWebPush::dispatch((int) $id, 'high');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Enviamos una notificación de prueba a %d dispositivo(s). Si no aparece en un minuto, revisa los permisos de notificaciones del sistema; si no llega a ninguno, el proceso de notificaciones del servidor no está corriendo.', $ids->count())]);

        return back();
    }

    /**
     * The id is only looked up among the user's own subscriptions, so another
     * user's id answers 404 and reveals nothing.
     */
    public function destroy(Request $request, int $subscription): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = PushSubscription::query()->where('user_id', $user->id)->whereKey($subscription)->delete();

        abort_if($deleted === 0, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ese navegador ya no recibirá notificaciones.']);

        return back();
    }
}
