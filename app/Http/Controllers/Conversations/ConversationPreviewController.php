<?php

namespace App\Http\Controllers\Conversations;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ConversationTimeline;
use App\Services\Personalization\ChatAppearances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The preview of a conversation from the inbox list (long press on touch
 * screens, right click or «Vista previa» on computers): its latest messages
 * without opening the chat.
 *
 * It only reads. It is a separate route from everything that changes the
 * reading state (conversations.read, notifications read) and never calls
 * them: the viewer's read marker, unread counters, notifications, the
 * conversation's state, assignment, position in the list, metrics and the
 * provider (no read receipt or typing indicator) stay exactly as they were.
 * The conversation is authorized like the chat itself, so a person who
 * cannot open it cannot preview it either (404, as if it did not exist).
 *
 * It is shown with the viewer's own effective chat colors, the same ones
 * the chat shows them (never the assigned agent's), only read: no
 * appearance row is created or changed.
 */
class ConversationPreviewController extends ConversationOperationController
{
    public function show(Request $request, Conversation $conversation, ConversationTimeline $timeline, ChatAppearances $appearances): JsonResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            ...$timeline->preview($conversation, $request),
            // Only what the bubbles need, not the editor's choices and presets.
            'appearance' => Arr::only($appearances->forViewer($viewer, $conversation), ['source', 'colors']),
        ]);
    }
}
