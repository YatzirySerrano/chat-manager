<?php

namespace App\Http\Controllers\WebChat;

use App\Concerns\ServesUserAvatars;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorAgentAvatars;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The profile picture of an agent who attends the visitor, behind the
 * signed URL of VisitorAgentAvatars, bound to the visitor's session ("s").
 * The session must still be usable, the agent must attend (or have
 * attended) one of its conversations and must not be a protected account;
 * anything else is the same 404.
 */
class WidgetAgentAvatarController extends Controller
{
    use ServesUserAvatars;

    public function show(Request $request, VisitorAgentAvatars $avatars, ProtectedAccounts $protected, string $user): Response
    {
        $session = WebChatSession::query()->with('connection')->where('public_id', (string) $request->query('s'))->first();

        abort_if($session === null || ! $session->isUsable() || $session->connection === null || $session->connection->isDeleted(), 404);

        $agent = User::query()->find((int) $user);

        abort_if($agent === null || $protected->hides($agent, null) || ! $avatars->attends($session, $agent), 404);

        return $this->avatarResponse($request, $agent, VisitorAgentAvatars::BROWSER_CACHE_SECONDS);
    }
}
