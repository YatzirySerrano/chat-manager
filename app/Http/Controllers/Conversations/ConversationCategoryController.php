<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\SetConversationCategory;
use App\Models\Conversation;
use App\Models\User;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The request category of a conversation, changed from the inbox details
 * panel. The engine authorizes the actor again under lock.
 */
class ConversationCategoryController extends ConversationOperationController
{
    public function update(Request $request, Conversation $conversation, SetConversationCategory $setCategory): RedirectResponse
    {
        $this->ensureVisible($conversation);

        /** @var User $actor */
        $actor = $request->user();

        // Permission before validation: a refusal is a 403, never a 422.
        $this->ensurePermitted($actor, 'categorize', $conversation);

        $validated = $request->validate(
            ['category_id' => ['present', 'nullable', 'integer', 'min:1']],
            attributes: ['category_id' => 'categoría'],
        );

        $changed = $setCategory->handle($conversation, $validated['category_id'] === null ? null : (int) $validated['category_id'], $actor);

        SaveToast::flash($changed, 'Categoría de la solicitud actualizada.');

        return back();
    }
}
