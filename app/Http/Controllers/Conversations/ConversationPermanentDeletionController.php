<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\DeleteConversationPermanently;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * «Eliminar chat definitivamente»: erases a conversation for good
 * (DeleteConversationPermanently). Only with the permission
 * permanent-deletion.conversations, which is reserved to the root authority.
 */
class ConversationPermanentDeletionController extends Controller
{
    /**
     * Word the person types to confirm (compared without case or spaces).
     */
    public const CONFIRMATION = 'ELIMINAR';

    public function __invoke(Request $request, Conversation $conversation, DeleteConversationPermanently $delete, HistoryImportPolicy $archive): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Out of reach looks exactly like "does not exist" (404); in reach
        // without the permission is a real 403. Archived history is reached
        // from its own screen, with its own permission.
        $visible = Gate::allows('view', $conversation) || $archive->viewArchived($actor, $conversation)->allowed();

        abort_unless($visible, 404);

        Gate::authorize('deletePermanently', $conversation);

        $request->validate(
            ['confirmation' => ['required', 'string', fn (string $attribute, mixed $value, callable $fail) => mb_strtoupper(trim((string) $value)) === self::CONFIRMATION
                ? null
                : $fail('Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar este chat para siempre.')]],
            ['confirmation.required' => 'Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar este chat para siempre.'],
        );

        $archived = $conversation->isHistoryOnly();
        $summary = $delete->handle($conversation, $actor);

        $message = "Chat eliminado definitivamente: {$summary['messages']} mensajes y {$summary['attachments']} archivos.";

        if ($summary['files_pending'] > 0) {
            $message .= " {$summary['files_pending']} archivos no se pudieron borrar del almacenamiento; quedaron registrados para revisarlos.";
        }

        Inertia::flash('toast', ['type' => $summary['files_pending'] > 0 ? 'warning' : 'success', 'message' => $message]);

        return $archived && $archive->viewArchive($actor)->allowed() && Route::has('admin.history.index')
            ? redirect()->route('admin.history.index')
            : redirect()->to(ConversationController::listUrl());
    }
}
