<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\VisitorBroadcastingAuthRequest;
use App\Models\WebChatSession;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authorizes the visitor's real-time subscription, outside the agents'
 * /broadcasting/auth and routes/channels*.php (which never know this
 * channel, so they reject it): only the exact private channel of the
 * authenticated session is signed, with the Pusher protocol signature
 * (HMAC-SHA256 of "{socket_id}:{channel}" with the Reverb secret).
 */
class VisitorBroadcastingAuthController extends Controller
{
    public function store(VisitorBroadcastingAuthRequest $request): JsonResponse
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');
        $channel = (string) $request->validated('channel_name');
        $key = config('broadcasting.connections.reverb.key');
        $secret = config('broadcasting.connections.reverb.secret');

        if (! hash_equals('private-'.$session->channelName(), $channel)
            || ! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw new AccessDeniedHttpException;
        }

        $signature = hash_hmac('sha256', $request->validated('socket_id').':'.$channel, $secret);

        return response()->json(['auth' => $key.':'.$signature]);
    }
}
