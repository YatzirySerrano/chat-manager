<?php

namespace App\Http\Controllers;

use App\Actions\Records\DeleteRecordPermanently;
use App\Http\Controllers\Conversations\ConversationPermanentDeletionController;
use App\Models\ConversationCategory;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * «Eliminar definitivamente» of records that were already deleted: tags,
 * request categories and quick replies (group «Eliminación definitiva»,
 * reserved to the root authority). Each one asks for the same typed
 * confirmation as erasing a chat.
 */
class PermanentRecordDeletionController extends Controller
{
    public function tag(Request $request, Tag $tag, DeleteRecordPermanently $delete): RedirectResponse
    {
        Gate::authorize('deletePermanently', $tag);

        return $this->erase($request, $tag, $delete, "Etiqueta {$tag->name} eliminada definitivamente.");
    }

    public function category(Request $request, ConversationCategory $category, DeleteRecordPermanently $delete): RedirectResponse
    {
        Gate::authorize('deletePermanently', $category);

        return $this->erase($request, $category, $delete, "Categoría {$category->name} eliminada definitivamente.");
    }

    public function quickReply(Request $request, QuickReply $quickReply, DeleteRecordPermanently $delete): RedirectResponse
    {
        Gate::authorize('deletePermanently', $quickReply);

        return $this->erase($request, $quickReply, $delete, "Respuesta rápida /{$quickReply->shortcut} eliminada definitivamente.");
    }

    private function erase(Request $request, Tag|ConversationCategory|QuickReply $record, DeleteRecordPermanently $delete, string $message): RedirectResponse
    {
        $word = ConversationPermanentDeletionController::CONFIRMATION;
        $reminder = "Escribe {$word} para confirmar la eliminación definitiva.";

        $request->validate(
            ['confirmation' => ['required', 'string', function (string $attribute, mixed $value, callable $fail) use ($word, $reminder): void {
                if (mb_strtoupper(trim((string) $value)) !== $word) {
                    $fail($reminder);
                }
            }]],
            ['confirmation.required' => $reminder],
        );

        /** @var User $actor */
        $actor = $request->user();

        $delete->handle($record, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
