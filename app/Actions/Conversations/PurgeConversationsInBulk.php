<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Enums\DataTransferType;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Vaciar conversaciones» (admin): erases a whole set of conversations for
 * good, each one through DeleteConversationPermanently (messages, files,
 * notes, timeline, metrics). Used to clean up after tests and to drop the
 * imported Whaticket history.
 *
 * Two scopes:
 * - closed: every live conversation already closed («Cerradas»). Contacts
 *   and their history are kept; only the conversations go.
 * - whaticket: every conversation imported from Whaticket (both the ones that
 *   also show in «Cerradas» and the archived, read-only history), plus the
 *   whole import staging (history_import_* and import_source_keys) and the
 *   import batches themselves, so «Historial importado» is left empty.
 *
 * Authorization is the caller's job (permanent-deletion.conversations,
 * reserved to the Superadministrador). The erase is driven a chunk at a time
 * from the screen (step()), so a scope with hundreds of thousands of messages
 * never holds one request for long and the progress is shown live.
 */
class PurgeConversationsInBulk
{
    public const SCOPE_CLOSED = 'closed';

    public const SCOPE_WHATICKET = 'whaticket';

    public const SCOPES = [self::SCOPE_CLOSED, self::SCOPE_WHATICKET];

    public function __construct(
        private DeleteConversationPermanently $deleteConversation,
        private AuditLogger $audit,
    ) {}

    /**
     * How many conversations the scope holds right now. A batch id narrows the
     * whaticket scope to a single import lote.
     */
    public function count(string $scope, ?int $batchId = null): int
    {
        return $this->query($scope, $batchId)->count();
    }

    /**
     * The conversations a scope targets. With a batch id, only the imported
     * conversations of that import lote.
     *
     * @return Builder<Conversation>
     */
    public function query(string $scope, ?int $batchId = null): Builder
    {
        if ($batchId !== null) {
            return Conversation::query()->imported()->where('imported_by_transfer_id', $batchId);
        }

        // «Cerradas» matches the inbox tab: every closed conversation that is
        // not archived history (live and imported-operational alike), so what
        // the user sees in «Cerradas» is exactly what this empties.
        return $scope === self::SCOPE_WHATICKET
            ? Conversation::query()->imported()
            : Conversation::query()->where('status', ConversationStatus::Closed)->notHistoryOnly();
    }

    /**
     * Deletes conversations of the scope in the current request (no queue), so
     * the screen can drive the erase a chunk at a time and show real progress.
     * Bounded BOTH by a count ($limit) and by time ($maxSeconds): heavy
     * conversations (thousands of messages) can make a fixed count exceed the
     * web server timeout, so the step returns as soon as either bound is hit,
     * after erasing at least one. Returns how many were deleted this step.
     */
    public function step(string $scope, User $actor, int $limit, ?int $batchId = null, float $maxSeconds = 10.0): int
    {
        $until = microtime(true) + $maxSeconds;
        $batch = $this->query($scope, $batchId)->orderBy('id')->limit(max(1, $limit))->get();
        $deleted = 0;

        foreach ($batch as $conversation) {
            // Skip the per-conversation audit entry (finish() writes a summary).
            $this->deleteConversation->handle($conversation, $actor, false);
            $deleted++;

            if (microtime(true) >= $until) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Once every conversation of the scope is gone, drops what is left of the
     * Whaticket import staging (whaticket scope or a single lote) and writes
     * the summary audit entry. The closed scope has no staging to drop.
     */
    public function finish(string $scope, User $actor, ?int $batchId = null): void
    {
        $batches = 0;

        if ($batchId !== null) {
            $batches = $this->dropImportStaging($batchId);
        } elseif ($scope === self::SCOPE_WHATICKET) {
            $batches = $this->dropImportStaging();
        }

        $this->audit->record(AuditAction::ConversationsBulkPurged, null, [
            'scope' => $batchId !== null ? 'whaticket_batch' : $scope,
            'batch_id' => $batchId,
            'import_batches' => $batches,
        ], $actor);
    }

    /**
     * Drops Whaticket import batches and their staging: every history import
     * batch, or a single one ($batchId). The two children with a
     * restrictOnDelete foreign key to `data_transfers` (`import_source_keys`
     * and `import_batch_records`) must go first; the rest of the staging
     * (`history_import_*`) cascades with the batch.
     *
     * @return int Batches dropped.
     */
    private function dropImportStaging(?int $batchId = null): int
    {
        return DB::transaction(function () use ($batchId): int {
            $batchIds = DB::table('data_transfers')
                ->where('type', DataTransferType::HistoryImport->value)
                ->when($batchId !== null, fn ($query) => $query->where('id', $batchId))
                ->pluck('id')
                ->all();

            if ($batchIds === []) {
                return 0;
            }

            // Both have a restrictOnDelete FK to data_transfers, so they block
            // the batch delete unless they go first.
            DB::table('import_source_keys')->whereIn('data_transfer_id', $batchIds)->delete();
            DB::table('import_batch_records')->whereIn('data_transfer_id', $batchIds)->delete();

            // history_import_files/conversations/messages/rules cascade with the batch.
            return DB::table('data_transfers')->whereIn('id', $batchIds)->delete();
        });
    }
}
