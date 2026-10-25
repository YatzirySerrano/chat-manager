<?php

namespace App\Listeners\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Signing out stops Web Push on this browser for the account: the logout
 * form sends the endpoint the browser had (push_endpoint, after removing it
 * locally) and only that subscription of the signing-out user is deleted.
 * Other devices of the same person keep theirs. Whoever signs in next on the
 * device starts without push until they enable it in their preferences.
 */
class ForgetPushSubscriptionOnLogout
{
    public function __construct(private Request $request) {}

    public function handle(Logout $event): void
    {
        $endpoint = $this->request->input('push_endpoint');

        if (! $event->user instanceof User || ! is_string($endpoint) || $endpoint === '' || strlen($endpoint) > 2048) {
            return;
        }

        PushSubscription::query()
            ->where('user_id', $event->user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();
    }
}
