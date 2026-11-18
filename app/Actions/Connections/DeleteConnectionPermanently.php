<?php

namespace App\Actions\Connections;

use App\Actions\Conversations\DeleteConversationPermanently;
use App\Enums\AuditAction;
use App\Jobs\PurgeConnection;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Queues;
use Illuminate\Support\Facades\DB;

/**
 * «Eliminar conexión definitivamente»: erases a connection for good with
 * every conversation it holds (each one through
 * DeleteConversationPermanently: messages, files, notes, timeline,
 * metrics), its templates, web chat widget and sessions, and its webhook
 * log. Contacts, their identities and their notes are kept; API tokens
 * simply stop listing the connection.
 *
 * It is the opposite of the logical deletion (DeleteConnection), which
 * keeps everything and can be restored. To keep the history, move the
 * conversations to another connection first (MoveConnectionConversations).
 *
 * start() deletes the connection logically right away (no traffic from that
 * moment) and queues PurgeConnection, which erases the conversations a
 * bounded slice at a time (a connection may hold hundreds of thousands of
 * messages) and finally the connection itself (finish()).
 *
 * Authorization is the caller's job (ConnectionPolicy::deletePermanently(),
 * permanent-deletion.connections, reserved to the root authority).
 */
class DeleteConnectionPermanently
{
    public function __construct(
        private DeleteConnection $deleteConnection,
        private DeleteConversationPermanently $deleteConversation,
        private AuditLogger $audit,
    ) {}

    /**
     * @return int Conversations that will be erased.
     */
    public function start(Connection $connection, User $actor): int
    {
        $this->deleteConnection->handle($connection, $actor);

        $conversations = Conversation::query()->where('connection_id', $connection->id)->count();

        // Nothing left to erase (for instance, everything was transferred
        // first): the connection goes right away.
        if ($conversations === 0 && $this->finish($connection, $actor, ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0])) {
            return 0;
        }

        PurgeConnection::dispatch($connection->id, $actor->id)->onQueue(Queues::name(Queues::EXPORTS));

        return $conversations;
    }

    /**
     * Erases conversations of the connection until none is left or the time
     * budget runs out.
     *
     * @return array{conversations: int, messages: int, attachments: int, files_pending: int, done: bool}
     */
    public function eraseConversations(Connection $connection, User $actor, float $seconds): array
    {
        $until = microtime(true) + $seconds;
        $totals = ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'files_pending' => 0, 'done' => false];

        do {
            $batch = Conversation::query()->where('connection_id', $connection->id)->orderBy('id')->limit(50)->get();

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
     * Erases the connection once it holds no conversation.
     *
     * @param  array{conversations: int, messages: int, attachments: int, files_pending: int}  $erased
     * @return bool Whether it was erased (false when a conversation is still there).
     */
    public function finish(Connection $connection, User $actor, array $erased): bool
    {
        return DB::transaction(function () use ($connection, $actor, $erased): bool {
            /** @var Connection|null $locked */
            $locked = Connection::query()->lockForUpdate()->find($connection->id);

            if ($locked === null) {
                return true;
            }

            if (Conversation::query()->where('connection_id', $locked->id)->exists()) {
                return false;
            }

            $templates = DB::table('message_templates')->where('connection_id', $locked->id)->delete();
            DB::table('web_chat_sessions')->where('connection_id', $locked->id)->delete();
            DB::table('web_chat_widgets')->where('connection_id', $locked->id)->delete();
            $webhooks = DB::table('webhook_events')->where('connection_id', $locked->id)->delete();

            // Integration tokens keep working for the rest of their connections.
            DB::table('api_tokens')->whereJsonContains('connection_ids', $locked->id)->orderBy('id')->get(['id', 'connection_ids'])
                ->each(function (object $token) use ($locked): void {
                    $ids = array_values(array_filter(
                        array_map('intval', (array) json_decode((string) $token->connection_ids, true)),
                        fn (int $id): bool => $id !== $locked->id,
                    ));
                    DB::table('api_tokens')->where('id', $token->id)->update(['connection_ids' => json_encode($ids)]);
                });

            $this->audit->record(AuditAction::ConnectionPermanentlyDeleted, $locked, [
                'connection_id' => $locked->id,
                'connection' => $locked->name,
                'channel' => $locked->channel->value,
                'templates' => $templates,
                'webhook_events' => $webhooks,
                ...$erased,
            ], $actor);

            // Departments and contact links go with it (ON DELETE CASCADE).
            DB::table('connections')->where('id', $locked->id)->delete();

            return true;
        });
    }
}
