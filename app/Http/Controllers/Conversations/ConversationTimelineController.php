<?php

namespace App\Http\Controllers\Conversations;

use App\Models\Conversation;
use App\Services\Conversations\ConversationTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON pages of a conversation's chat for the inbox: older pages
 * (`before`), what arrived after the newest known items (`after_*`), and the
 * current state of items already shown (`messages[]`, `notes[]`). The
 * conversation is authorized on every request, so a person who lost access
 * stops receiving anything at once.
 */
class ConversationTimelineController extends ConversationOperationController
{
    public function index(Request $request, Conversation $conversation, ConversationTimeline $timeline): JsonResponse
    {
        $this->ensureVisible($conversation);

        $validated = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after_message' => ['nullable', 'integer', 'min:0'],
            'after_note' => ['nullable', 'integer', 'min:0'],
            'after_event' => ['nullable', 'integer', 'min:0'],
            'messages' => ['nullable', 'array', 'max:100'],
            'messages.*' => ['integer', 'min:1'],
            'notes' => ['nullable', 'array', 'max:100'],
            'notes.*' => ['integer', 'min:1'],
        ]);

        if (isset($validated['messages']) || isset($validated['notes'])) {
            return response()->json($timeline->refresh(
                $conversation,
                $request,
                array_values(array_map('intval', $validated['messages'] ?? [])),
                array_values(array_map('intval', $validated['notes'] ?? [])),
            ));
        }

        if ($request->hasAny(['after_message', 'after_note', 'after_event'])) {
            return response()->json($timeline->after(
                $conversation,
                $request,
                (int) ($validated['after_message'] ?? 0),
                (int) ($validated['after_note'] ?? 0),
                (int) ($validated['after_event'] ?? 0),
            ));
        }

        return response()->json($timeline->page(
            $conversation,
            $request,
            isset($validated['before']) ? (int) $validated['before'] : null,
        ));
    }
}
