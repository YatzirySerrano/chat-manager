<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Web Push subscriptions of the authenticated user's browsers, managed from
 * the notification preferences page. A browser belongs to whoever
 * registered it last (upsert by endpoint hash), and every user only ever
 * sees or deletes their own.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notificaciones push activadas en este navegador.']);

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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispositivo eliminado de las notificaciones push.']);

        return back();
    }
}
