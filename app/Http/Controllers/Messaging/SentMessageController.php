<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\ChangeSentMessage;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Editing and deleting for everyone the messages the team sent by a QR
 * connection (ChangeSentMessage). Agents and administrators alike: whoever
 * sent it or whoever can reply in the conversation.
 */
class SentMessageController extends Controller
{
    public function update(Request $request, Conversation $conversation, Message $message, ChangeSentMessage $change): RedirectResponse|JsonResponse
    {
        $actor = $this->authorizeChange($request, $conversation, $message);
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        $change->edit($conversation, $message, (string) $data['body'], $actor);

        return $this->done($request, $message, 'Mensaje editado en WhatsApp.');
    }

    public function destroy(Request $request, Conversation $conversation, Message $message, ChangeSentMessage $change): RedirectResponse|JsonResponse
    {
        $actor = $this->authorizeChange($request, $conversation, $message);

        $change->delete($conversation, $message, $actor);

        return $this->done($request, $message, 'Mensaje eliminado para todos en WhatsApp.');
    }

    private function authorizeChange(Request $request, Conversation $conversation, Message $message): User
    {
        abort_unless(Gate::allows('view', $conversation), 404);
        abort_unless($message->conversation_id === $conversation->id, 404);

        /** @var User $actor */
        $actor = $request->user();

        abort_unless($message->sender_user_id === $actor->id || Gate::allows('reply', $conversation), 403, 'Solo quien envió el mensaje o quien atiende la conversación puede cambiarlo.');

        return $actor;
    }

    private function done(Request $request, Message $message, string $text): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['data' => ['message_id' => $message->id, 'body' => $message->body, 'edited_at' => $message->edited_at?->toIso8601String(), 'revoked_at' => $message->revoked_at?->toIso8601String()]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $text]);

        return back();
    }
}
