<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\RevokeAttachmentLink;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Revocation of a customer's download link (AttachmentLinkController). Only
 * the agent who sent the file or someone who can reply in the conversation
 * may revoke it; a conversation outside the actor's reach is a 404.
 */
class AttachmentLinkRevocationController extends Controller
{
    public function store(Request $request, Conversation $conversation, Message $message, MessageAttachment $attachment, RevokeAttachmentLink $revoke): RedirectResponse|JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);
        abort_unless($message->conversation_id === $conversation->id && $attachment->message_id === $message->id, 404);

        /** @var User $actor */
        $actor = $request->user();

        abort_unless($message->sender_user_id === $actor->id || Gate::allows('reply', $conversation), 403, 'Solo quien envió el archivo o quien atiende la conversación puede revocar el enlace.');

        $revoke->handle($conversation, $message, $attachment, $actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['data' => ['message_id' => $message->id, 'revoked' => true]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'El enlace de descarga se revocó; el cliente ya no podrá descargar el archivo.']);

        return back();
    }
}
