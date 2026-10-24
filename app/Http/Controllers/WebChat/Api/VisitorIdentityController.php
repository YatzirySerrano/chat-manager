<?php

namespace App\Http\Controllers\WebChat\Api;

use App\Enums\WebChatIdentityMode;
use App\Exceptions\ConversationConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebChat\VisitorIdentityRequest;
use App\Http\Resources\WebChat\VisitorSessionResource;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorIdentity;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;

/**
 * The host site identifies its signed-in user to the widget. A session
 * that has not written yet changes identity (200, same token); one that
 * already wrote is replaced by a new identified session (201 with its
 * token), so the anonymous history is never attached to the person.
 */
class VisitorIdentityController extends Controller
{
    public function store(VisitorIdentityRequest $request, VisitorIdentity $identities, VisitorSessions $sessions): JsonResponse
    {
        /** @var WebChatSession $session */
        $session = $request->attributes->get('webchat_session');
        $connection = $session->connection;
        $widget = $connection->webChatWidget;

        if ($widget === null || $widget->identity_mode === WebChatIdentityMode::Anonymous) {
            throw new ConversationConflict('conflict');
        }

        $identity = $identities->verify($widget, $request->identity());

        if ($identity === null) {
            throw new AuthenticationException;
        }

        ['session' => $identified, 'token' => $token] = $sessions->identify($session, $identity);
        $identified->setRelation('connection', $connection);
        $request->attributes->set('webchat_session', $identified);

        $resource = new VisitorSessionResource($identified);

        if ($token === null) {
            return $resource->response();
        }

        return $resource->additional(['token' => $token])->response()->setStatusCode(201);
    }
}
