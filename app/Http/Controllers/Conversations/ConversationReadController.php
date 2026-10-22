<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\MarkConversationRead;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The inbox tells the server the viewer has read the conversation up to a
 * message while its chat is on screen. Only people who can see the
 * conversation have a marker; it is personal and changes nothing else.
 */
class ConversationReadController extends ConversationOperationController
{
    public function store(Request $request, Conversation $conversation, MarkConversationRead $markRead): JsonResponse
    {
        $this->ensureVisible($conversation);

        $validated = $request->validate([
            'message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();

        $moved = $markRead->handle($conversation, $viewer, isset($validated['message_id']) ? (int) $validated['message_id'] : null);

        return response()->json(['moved' => $moved]);
    }
}
