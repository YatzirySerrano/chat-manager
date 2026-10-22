<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SyncConversationTags;
use App\Models\Conversation;
use App\Models\User;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tags of a conversation, changed from the inbox details panel. The engine
 * authorizes the actor again under lock and audits the change.
 */
class ConversationTagController extends ConversationOperationController
{
    public function update(Request $request, Conversation $conversation, SyncConversationTags $syncTags): RedirectResponse
    {
        $this->ensureVisible($conversation);

        $validated = $request->validate([
            'tag_ids' => ['present', 'array', 'max:50'],
            'tag_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $changes = $syncTags->handle($conversation, array_values(array_map('intval', $validated['tag_ids'])), $actor);

        SaveToast::flash($changes['added'] !== [] || $changes['removed'] !== [], 'Etiquetas de la conversación actualizadas.');

        return back();
    }
}
