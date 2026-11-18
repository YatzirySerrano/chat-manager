<?php

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves many conversations of one connection to another one of the same
 * channel in a single operation: a whole selection, or every conversation
 * the connection lists (ConnectionConversations), never one by one.
 *
 * The usual case is the Whaticket history: the importer keeps it under a
 * historical connection (created deleted, so it never appears among the
 * operational ones) and here it is taken to the real connection of the
 * number, where it is listed with that connection's conversations.
 *
 * What moves with each conversation: its messages and timeline (they hang
 * from the conversation), the copies of the connection in its metrics and
 * response cycles, and the contact's link with the destination connection.
 * Imported history stays imported (closed, read-only, outside the metrics);
 * with $showArchived, history archived as read-only (only in «Historial
 * importado») becomes regular closed history of the inbox.
 *
 * Every selected conversation moves. A contact has a single active
 * conversation per connection (conversations_single_open_unique): when the
 * contact is active on both sides, the one with the latest message stays
 * active and the other is closed (counted as `closed`), never an error.
 *
 * Authorization is the caller's job (ConnectionPolicy::moveConversations()).
 */
class MoveConnectionConversations
{
    private const CHUNK = 500;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<int>  $conversationIds  Candidate ids, already narrowed to the source connection by the caller.
     * @return array{moved: int, closed: int, unarchived: int}
     *
     * @throws ValidationException When the destination cannot receive them.
     */
    public function handle(Connection $source, Connection $target, array $conversationIds, bool $showArchived, User $actor): array
    {
        $this->ensureValidTarget($source, $target);

        $totals = ['moved' => 0, 'closed' => 0, 'unarchived' => 0];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $conversationIds))), self::CHUNK) as $chunk) {
            $result = DB::transaction(fn (): array => $this->moveChunk($source, $target, $chunk, $showArchived, $actor));

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        if ($totals['moved'] > 0) {
            $this->audit->record(AuditAction::ConnectionConversationsMoved, $target, [
                'from_connection_id' => $source->id,
                'from_connection' => $source->name,
                'to_connection' => $target->name,
                ...$totals,
            ], $actor);
        }

        return $totals;
    }

    /**
     * @throws ValidationException
     */
    public function ensureValidTarget(Connection $source, Connection $target): void
    {
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_connection_id' => 'Elige una conexión distinta de la actual.']);
        }

        if ($target->isDeleted()) {
            throw ValidationException::withMessages(['target_connection_id' => 'La conexión de destino está eliminada. Restáurala o elige otra.']);
        }

        if ($target->channel !== $source->channel) {
            throw ValidationException::withMessages(['target_connection_id' => "Solo se pueden mover a otra conexión de {$source->channel->label()}."]);
        }
    }

    /**
     * @param  list<int>  $chunk
     * @return array{moved: int, closed: int, unarchived: int}
     */
    private function moveChunk(Connection $source, Connection $target, array $chunk, bool $showArchived, User $actor): array
    {
        $rows = Conversation::query()
            ->whereKey($chunk)
            ->where('connection_id', $source->id)
            ->lockForUpdate()
            ->get(['id', 'contact_id', 'status', 'history_only_at', 'last_message_at']);

        if ($rows->isEmpty()) {
            return ['moved' => 0, 'closed' => 0, 'unarchived' => 0];
        }

        $closed = ConversationStatus::Closed;
        $active = $rows->filter(fn (Conversation $row): bool => $row->status !== $closed);

        // A contact has one active conversation per connection: when both
        // sides have one, the most recent stays active and the other is
        // closed, so the move never fails.
        $rivals = $active->isEmpty() ? collect() : Conversation::query()
            ->where('connection_id', $target->id)
            ->whereIn('contact_id', $active->pluck('contact_id')->unique()->all())
            ->where('status', '!=', $closed->value)
            ->lockForUpdate()
            ->get(['id', 'contact_id', 'last_message_at'])
            ->keyBy('contact_id');

        $toClose = [];

        foreach ($active as $row) {
            $rival = $rivals->get($row->contact_id);

            if ($rival === null) {
                continue;
            }

            $sourceIsNewer = ($row->last_message_at?->getTimestamp() ?? 0) > ($rival->last_message_at?->getTimestamp() ?? 0);
            $toClose[] = $sourceIsNewer ? $rival->id : $row->id;
        }

        if ($toClose !== []) {
            DB::table('conversations')->whereIn('id', $toClose)->update([
                'status' => $closed->value,
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        }

        $ids = $rows->modelKeys();
        $unarchived = $showArchived ? $rows->filter(fn (Conversation $row): bool => $row->history_only_at !== null)->modelKeys() : [];

        DB::table('conversations')->whereIn('id', $ids)->update([
            'connection_id' => $target->id,
            // Open screens of these conversations reload with the new connection.
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);

        if ($unarchived !== []) {
            DB::table('conversations')->whereIn('id', $unarchived)->update(['history_only_at' => null]);
        }

        foreach (['conversation_metrics', 'conversation_agent_metrics', 'response_cycles'] as $table) {
            DB::table($table)->whereIn('conversation_id', $ids)->update(['connection_id' => $target->id]);
        }

        $now = now();
        DB::table('connection_contact')->insertOrIgnore($rows->pluck('contact_id')->unique()->values()
            ->map(fn (mixed $contactId): array => [
                'connection_id' => $target->id,
                'contact_id' => (int) $contactId,
                'first_interaction_at' => null,
                'last_interaction_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

        return ['moved' => count($ids), 'closed' => count($toClose), 'unarchived' => count($unarchived)];
    }
}
