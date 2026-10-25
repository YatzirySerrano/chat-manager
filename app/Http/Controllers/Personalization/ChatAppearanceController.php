<?php

namespace App\Http\Controllers\Personalization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Personalization\ChatAppearanceRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Personalization\ChatAppearances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The viewer's own chat colors, changed from a conversation they can see:
 * for that conversation only or as their default for every chat. Answers
 * with the appearance the chat must show now, so it changes without
 * reloading the page.
 */
class ChatAppearanceController extends Controller
{
    public function update(ChatAppearanceRequest $request, Conversation $conversation, ChatAppearances $appearances): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        ['adjusted' => $adjusted] = $appearances->save(
            $viewer,
            $request->appliesToAll() ? null : $conversation,
            $request->preset(),
            $request->colors(),
        );

        return response()->json([
            'appearance' => $appearances->forViewer($viewer, $conversation),
            'notice' => $adjusted ? ChatAppearances::ADJUSTED_NOTICE : null,
        ]);
    }

    /**
     * Restores the defaults: of this conversation (back to the user's
     * default) or of every chat (back to the platform theme).
     */
    public function destroy(Request $request, Conversation $conversation, ChatAppearances $appearances): JsonResponse
    {
        abort_unless(Gate::allows('view', $conversation), 404);

        $validated = $request->validate([
            'scope' => ['required', Rule::in([ChatAppearanceRequest::SCOPE_CONVERSATION, ChatAppearanceRequest::SCOPE_ALL])],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();

        $appearances->clear($viewer, $validated['scope'] === ChatAppearanceRequest::SCOPE_ALL ? null : $conversation);

        return response()->json([
            'appearance' => $appearances->forViewer($viewer, $conversation),
            'notice' => null,
        ]);
    }
}
