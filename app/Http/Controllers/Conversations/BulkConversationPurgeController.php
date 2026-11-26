<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\PurgeConversationsInBulk;
use App\Enums\DataTransferType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\DataTransfer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Vaciar conversaciones definitivamente» (Superadministrador): erases a whole
 * scope — every closed conversation, the whole imported Whaticket history, or a
 * single imported lote — a chunk at a time within the request, so the screen
 * drives it with a real progress bar and the erase never depends on a queue
 * worker. Reserved to the root authority (permanent-deletion.conversations) and
 * confirmed by typing ELIMINAR on every step.
 */
class BulkConversationPurgeController extends Controller
{
    public const CONFIRMATION = 'ELIMINAR';

    /** Conversations erased per request: enough to be quick, few enough to stay well under the timeout. */
    private const CHUNK = 20;

    public function __invoke(Request $request, PurgeConversationsInBulk $purge): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->holdsPermission(Permission::PurgeConversations), 403);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(PurgeConversationsInBulk::SCOPES)],
            'batch_id' => ['nullable', 'integer'],
            'confirmation' => ['required', 'string', fn (string $attribute, mixed $value, callable $fail) => mb_strtoupper(trim((string) $value)) === self::CONFIRMATION
                ? null
                : $fail('Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar estas conversaciones para siempre.')],
        ], ['confirmation.required' => 'Escribe '.self::CONFIRMATION.' para confirmar que quieres borrar estas conversaciones para siempre.']);

        $scope = (string) $validated['scope'];
        $batchId = isset($validated['batch_id']) ? (int) $validated['batch_id'] : null;

        if ($batchId !== null) {
            abort_unless(
                DataTransfer::query()->whereKey($batchId)->where('type', DataTransferType::HistoryImport)->exists(),
                404,
            );
        }

        $deleted = $purge->step($scope, $actor, self::CHUNK, $batchId);
        // The staging rows (Whaticket import) are dropped after the
        // conversations, so they count as remaining work until they are gone.
        $remaining = $purge->count($scope, $batchId) + $purge->stagingCount($scope, $batchId);
        $done = $remaining === 0;

        // The staging and the summary audit entry are written once, at the end.
        if ($done) {
            $purge->finish($scope, $actor, $batchId);
        }

        return response()->json([
            'deleted' => $deleted,
            'remaining' => $remaining,
            'done' => $done,
        ]);
    }
}
