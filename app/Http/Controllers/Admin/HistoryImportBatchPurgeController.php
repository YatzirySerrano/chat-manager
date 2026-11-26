<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Conversations\PurgeConversationsInBulk;
use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeConversationsBulk;
use App\Models\DataTransfer;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «Eliminar lote importado definitivamente» (Superadministrador): erases a
 * whole Whaticket import lote — its imported conversations and messages — and
 * its import staging and batch row. Unlike «Descartar lote» (which only cancels
 * a draft that imported nothing), this removes what a confirmed import created.
 *
 * Reserved to the root authority (permanent-deletion.conversations) and
 * confirmed by typing ELIMINAR. Runs in the background a slice at a time
 * (PurgeConversationsBulk with the lote's id).
 */
class HistoryImportBatchPurgeController extends Controller
{
    public const CONFIRMATION = 'ELIMINAR';

    public function __invoke(Request $request, DataTransfer $transfer, PurgeConversationsInBulk $purge): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->holdsPermission(Permission::PurgeConversations), 403);
        abort_unless($transfer->type === DataTransferType::HistoryImport, 404);

        $request->validate([
            'confirmation' => ['required', 'string', fn (string $attribute, mixed $value, callable $fail) => mb_strtoupper(trim((string) $value)) === self::CONFIRMATION
                ? null
                : $fail('Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar este lote para siempre.')],
        ], ['confirmation.required' => 'Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar este lote para siempre.']);

        $count = $purge->count(PurgeConversationsInBulk::SCOPE_WHATICKET, $transfer->id);

        PurgeConversationsBulk::dispatch(PurgeConversationsInBulk::SCOPE_WHATICKET, $actor->id, ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0], $transfer->id)
            ->onQueue(Queues::name(Queues::EXPORTS));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count > 0
                ? "Se inició el borrado definitivo del lote con {$count} conversaciones. Desaparecerá en unos momentos."
                : 'Se eliminó el lote importado.',
        ]);

        return back();
    }
}
