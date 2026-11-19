<?php

namespace App\Services\WebChat;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WebChatSession;
use App\Support\ProtectedAccounts;
use Illuminate\Support\Facades\URL;

/**
 * Profile pictures of the agents a web chat visitor talks to. The visitor
 * never gets the staff URL (users.avatar needs a signed-in person): each
 * picture goes through a signed URL bound to the visitor's session, the
 * agent and the picture's version (WidgetAgentAvatarController), so it is
 * stable while the picture and the session last and the browser may keep
 * it. The URL is relative (the frame and the picture share the origin), so
 * it works behind a proxy whatever APP_URL says. It serves only agents who attend or attended one of the session's
 * conversations, and never a protected account (shown as «Administración
 * del sistema», without a picture).
 */
class VisitorAgentAvatars
{
    /** The visitor's browser may keep a picture this long (its URL carries the version). */
    public const BROWSER_CACHE_SECONDS = 86400;

    public function __construct(
        private ProtectedAccounts $protected,
        private VisitorSessions $sessions,
    ) {}

    /**
     * Signed URL of the agent's picture for the session, or null without a
     * picture (initials instead) or for a protected account. Expects the
     * agent's avatar columns loaded.
     */
    public function url(?User $agent, ?WebChatSession $session): ?string
    {
        if ($agent === null || $session === null || ! $agent->hasAvatar() || $this->protected->hides($agent, null)) {
            return null;
        }

        return URL::signedRoute('widget.agents.avatar', [
            'user' => $agent->id,
            's' => $session->public_id,
            'v' => $agent->avatar_version ?? '1',
        ], absolute: false);
    }

    /**
     * Whether the agent attends one of the session's conversations or wrote
     * a message the session can see.
     */
    public function attends(WebChatSession $session, User $agent): bool
    {
        if ($session->contact_id === null) {
            return false;
        }

        return Conversation::query()
            ->where('connection_id', $session->connection_id)
            ->where('contact_id', $session->contact_id)
            ->where('assigned_user_id', $agent->id)
            ->exists()
            || $this->sessions->visibleMessages($session)->where('sender_user_id', $agent->id)->exists();
    }
}
