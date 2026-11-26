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
 * reserved to the Superadministrador). The work is bounded by time and driven
 * from a queued job (PurgeConversationsBulk), since a scope may hold hundreds
 * of thousands of messages.
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
     * How many conversations the scope holds right now.
     */
    public function count(string $scope): int
    {
        return $this->query($scope)->count();
    }

    /**
     * The conversations a scope targets.
     *
     * @return Builder<Conversation>
     */
    public function query(string $scope): Builder
    {
        return $scope === self::SCOPE_WHATICKET
            ? Conversation::query()->imported()
            : Conversation::query()->live()->where('status', ConversationStatus::Closed);
    }

    /**
     * Erases conversations of the scope until none is left or the time budget
     * runs out.
     *
     * @return array{conversations: int, messages: int, attachments: int, files_pending: int, done: bool}
     */
    public function erase(string $scope, User $actor, float $seconds): array
    {
        $until = microtime(true) + $seconds;
        $totals = ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0, 'done' => false];

        do {
            $batch = $this->query($scope)->orderBy('id')->limit(50)->get();

            if ($batch->isEmpty()) {
                $totals['done'] = true;

                break;
            }

            foreach ($batch as $conversation) {
                $summary = $this->deleteConversation->handle($conversation, $actor);
                $totals['conversations']++;
                $totals['messages'] += $summary['messages'];
                $totals['attachments'] += $summary['attachments'];
                $totals['files_pending'] += $summary['files_pending'];

                if (microtime(true) >= $until) {
                    return $totals;
                }
            }
        } while (true);

        return $totals;
    }

    /**
     * Once every conversation of the scope is gone, drops what is left of the
     * Whaticket import staging (whaticket scope only) and writes the summary
     * audit entry. The closed scope has no staging to drop.
     *
     * @param  array{conversations: int, messages: int, attachments: int, files_pending: int}  $erased
     */
    public function finish(string $scope, User $actor, array $erased): void
    {
        $batches = 0;

        if ($scope === self::SCOPE_WHATICKET) {
            $batches = $this->dropImportStaging();
        }

        $this->audit->record(AuditAction::ConversationsBulkPurged, null, [
            'scope' => $scope,
            'import_batches' => $batches,
            ...$erased,
        ], $actor);
    }

    /**
     * Drops every Whaticket import batch and its staging. `import_source_keys`
     * must go first (its foreign key to `data_transfers` is restrictOnDelete);
     * the rest of the staging (`history_import_*`) cascades with the batch.
     *
     * @return int Batches dropped.
     */
    private function dropImportStaging(): int
    {
        return DB::transaction(function (): int {
            $batchIds = DB::table('data_transfers')
                ->where('type', DataTransferType::HistoryImport->value)
                ->pluck('id')
                ->all();

            if ($batchIds === []) {
                return 0;
            }

            DB::table('import_source_keys')->whereIn('data_transfer_id', $batchIds)->delete();

            // history_import_files/conversations/messages/rules cascade with the batch.
            return DB::table('data_transfers')->whereIn('id', $batchIds)->delete();
        });
    }
}
