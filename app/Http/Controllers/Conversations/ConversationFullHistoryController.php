<?php

namespace App\Http\Controllers\Conversations;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ContactFullHistory;
use App\Services\Personalization\ChatAppearances;
use App\Support\Personalization\ViewerAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * JSON pages of «Conversación completa»: the whole history of the
 * conversation's contact across every conversation the viewer may open
 * (ContactFullHistory), newest page first, then older ones with the `next`
 * cursor of the previous page (`before_conversation`, plus
 * `before_message` when that page ended inside a conversation).
 *
 * The conversation it is opened from is authorized like its chat (404 when
 * the viewer may not see it); every other conversation is filtered by the
 * same rule, so nothing outside the viewer's reach is ever listed. It only
 * reads: the read marker, counters and notifications stay as they were. The
 * bubbles take the viewer's own chat colors, as in the preview.
 */
class ConversationFullHistoryController extends ConversationOperationController
{
    public function index(Request $request, Conversation $conversation, ContactFullHistory $history, ChatAppearances $appearances): JsonResponse
    {
        $this->ensureVisible($conversation);

        $validated = $request->validate([
            'before_conversation' => ['nullable', 'integer', 'min:1'],
            'before_message' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $before = isset($validated['before_conversation']) ? (int) $validated['before_conversation'] : null;
        $contact = $conversation->contact;

        return response()->json([
            ...$history->page(
                $conversation,
                $viewer,
                $request,
                $before,
                $before !== null && isset($validated['before_message']) ? (int) $validated['before_message'] : null,
            ),
            'contact' => ['display_name' => ViewerAlias::displayName($contact, ViewerAlias::of($contact, $viewer))],
            'appearance' => Arr::only($appearances->forViewer($viewer, $conversation), ['source', 'colors']),
        ]);
    }
}
