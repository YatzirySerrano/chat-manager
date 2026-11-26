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
     * Staging rows that belong to the Whaticket import and would otherwise be
     * dropped all at once with the batch (a huge cascade that can exceed the
     * web server timeout). Deleted in chunks, children first, so no single
     * statement is large. Every one has a `data_transfer_id` column.
     *
     * @var list<string>
     */
    private const STAGING_TABLES = [
        'history_import_messages',
        'history_import_conversations',
        'history_import_rules',
        'history_import_files',
        'import_source_keys',
        'import_batch_records',
    ];

    /** Staging rows deleted per statement. */
    private const STAGING_CHUNK = 2000;

    /**
     * Whether the scope carries Whaticket import staging (whaticket scope or a
     * single lote); the closed scope never does.
     */
    private function hasStaging(string $scope, ?int $batchId): bool
    {
        return $batchId !== null || $scope === self::SCOPE_WHATICKET;
    }

    /**
     * The import batches (data_transfers) in the scope: every history import
     * batch, or a single one.
     *
     * @return list<int>
     */
    private function stagingBatchIds(string $scope, ?int $batchId): array
    {
        if (! $this->hasStaging($scope, $batchId)) {
            return [];
        }

        $ids = DB::table('data_transfers')
            ->where('type', DataTransferType::HistoryImport->value)
            ->when($batchId !== null, fn ($query) => $query->where('id', $batchId))
            ->pluck('id')
            ->all();

        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * How many import staging rows the scope still holds (0 for the closed
     * scope). Added to the conversation count so the progress bar covers
     * dropping the staging too.
     */
    public function stagingCount(string $scope, ?int $batchId = null): int
    {
        $batchIds = $this->stagingBatchIds($scope, $batchId);

        if ($batchIds === []) {
            return 0;
        }

        $total = 0;

        foreach (self::STAGING_TABLES as $table) {
            $total += DB::table($table)->whereIn('data_transfer_id', $batchIds)->count();
        }

        return $total;
    }

    /**
     * Erases the scope in the current request (no queue), so the screen can
     * drive it a chunk at a time and show real progress. First the
     * conversations (each through DeleteConversationPermanently), then, once
     * none is left, the import staging a chunk at a time. Bounded BOTH by a
     * count ($limit) and by time ($maxSeconds): a heavy conversation or a huge
     * staging can exceed the web server timeout, so the step returns as soon as
     * either bound is hit, after erasing at least one row. Returns how many
     * rows (conversations or staging) were deleted this step.
     */
    public function step(string $scope, User $actor, int $limit, ?int $batchId = null, float $maxSeconds = 10.0): int
    {
        $until = microtime(true) + $maxSeconds;
        $deleted = 0;

        $conversations = $this->query($scope, $batchId)->orderBy('id')->limit(max(1, $limit))->get();

        foreach ($conversations as $conversation) {
            // Skip the per-conversation audit entry (finish() writes a summary).
            $this->deleteConversation->handle($conversation, $actor, false);
            $deleted++;

            if (microtime(true) >= $until) {
                return $deleted;
            }
        }

        // Only once the conversations are gone: drop the import staging that
        // would otherwise cascade with the batch all at once.
        if ($this->query($scope, $batchId)->count() === 0) {
            $deleted += $this->deleteStagingChunk($scope, $batchId, $until);
        }

        return $deleted;
    }

    /**
     * Deletes a time-bounded chunk of the import staging (children first), so a
     * batch with tens of thousands of staging rows is dropped across several
     * steps instead of one huge cascade. Returns rows deleted this chunk.
     */
    private function deleteStagingChunk(string $scope, ?int $batchId, float $until): int
    {
        $batchIds = $this->stagingBatchIds($scope, $batchId);

        if ($batchIds === []) {
            return 0;
        }

        $deleted = 0;

        foreach (self::STAGING_TABLES as $table) {
            do {
                $removed = DB::table($table)->whereIn('data_transfer_id', $batchIds)->limit(self::STAGING_CHUNK)->delete();
                $deleted += $removed;

                if (microtime(true) >= $until) {
                    return $deleted;
                }
            } while ($removed > 0);
        }

        return $deleted;
    }

    /**
     * Once every conversation AND every staging row of the scope is gone, drops
     * the now-childless import batches and writes the summary audit entry. The
     * closed scope has no batch to drop.
     */
    public function finish(string $scope, User $actor, ?int $batchId = null): void
    {
        $batches = 0;
        $batchIds = $this->stagingBatchIds($scope, $batchId);

        if ($batchIds !== []) {
            $batches = DB::transaction(function () use ($batchIds): int {
                // Belt and suspenders: the restrict-FK children are already gone
                // (stagingCount reached 0), so these are no-ops that guarantee
                // the batch delete never hits a foreign key.
                DB::table('import_source_keys')->whereIn('data_transfer_id', $batchIds)->delete();
                DB::table('import_batch_records')->whereIn('data_transfer_id', $batchIds)->delete();

                return DB::table('data_transfers')->whereIn('id', $batchIds)->delete();
            });
        }

        $this->audit->record(AuditAction::ConversationsBulkPurged, null, [
            'scope' => $batchId !== null ? 'whaticket_batch' : $scope,
            'batch_id' => $batchId,
            'import_batches' => $batches,
        ], $actor);
    }
}
