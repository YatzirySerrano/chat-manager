<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\PurgeConversationsInBulk;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeConversationsBulk;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * «Vaciar conversaciones definitivamente»: starts the bulk, permanent erase of
 * a whole scope — every closed conversation, or the whole imported Whaticket
 * history. Reserved to the Superadministrador (permanent-deletion.conversations,
 * reserved to the root authority). The erase runs in the background
 * (PurgeConversationsBulk), a bounded slice at a time.
 */
class BulkConversationPurgeController extends Controller
{
    /**
     * Word the person types to confirm (compared without case or spaces).
     */
    public const CONFIRMATION = 'ELIMINAR';

    public function __invoke(Request $request, PurgeConversationsInBulk $purge): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->holdsPermission(Permission::PurgeConversations), 403);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(PurgeConversationsInBulk::SCOPES)],
            'confirmation' => ['required', 'string', fn (string $attribute, mixed $value, callable $fail) => mb_strtoupper(trim((string) $value)) === self::CONFIRMATION
                ? null
                : $fail('Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar estas conversaciones para siempre.')],
        ], ['confirmation.required' => 'Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar estas conversaciones para siempre.']);

        $scope = (string) $validated['scope'];
        $count = $purge->count($scope);

        PurgeConversationsBulk::dispatch($scope, $actor->id)->onQueue(Queues::name(Queues::EXPORTS));

        $label = $scope === PurgeConversationsInBulk::SCOPE_WHATICKET ? 'el historial de Whaticket' : 'las conversaciones cerradas';

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count > 0
                ? "Se inició el borrado definitivo de {$count} conversaciones de {$label}. Desaparecerán en unos momentos."
                : "No hay conversaciones que borrar en {$label}.",
        ]);

        return back();
    }
}
