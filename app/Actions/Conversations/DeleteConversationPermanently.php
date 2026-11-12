<?php

namespace App\Actions\Conversations;

use App\Broadcasting\RealtimeChannels;
use App\Broadcasting\RealtimeSignals;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaThumbnails;
use App\Services\Storage\MountHealth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Erases a conversation for good: its messages, the files of those messages
 * (with their previews), notes, timeline, response cycles, metrics, read
 * marks, tags, per-chat appearances and the notifications that point at it.
 * The contact, its notes, its identities and the record of how it reached
 * the platform (contact attributions) are kept.
 *
 * Authorization (permanent-deletion.conversations, reserved to the root
 * authority) is the caller's job: ConversationPolicy::deletePermanently().
 *
 * Order, chosen so a failure never leaves the database pointing at files
 * that no longer exist:
 *
 * 1. One transaction, with the conversation locked: every row is deleted
 *    and the audit entry is written. Either all of it happens or none.
 * 2. After the commit, the files are removed from their disks. A file that
 *    cannot be removed (the NAS is not mounted, a timeout) is only an
 *    orphan on the storage: it is logged and counted in the audit entry
 *    (`files_pending`), and nothing references it any more. The database
 *    is never rolled back for it.
 * 3. A real-time signal makes it disappear from the open inboxes.
 *
 * Imported history (Whaticket) can be erased too: its keys in
 * import_source_keys go with it, so importing the same export again brings
 * the conversation back instead of trying to extend one that is gone.
 *
 * The audit entry is restricted to the root authority and carries no
 * content: who, when, the contact, the connection and how much was erased.
 */
class DeleteConversationPermanently
{
    public function __construct(
        private AuditLogger $audit,
        private RealtimeSignals $signals,
        private MediaDisk $mediaDisk,
        private MountHealth $mounts,
    ) {}

    /**
     * @return array{messages: int, attachments: int, files_deleted: int, files_pending: int}
     *
     * @throws ModelNotFoundException When the conversation no longer exists.
     */
    public function handle(Conversation $conversation, User $actor): array
    {
        // Audience of the signal, read before the rows that define it go.
        $inboxChannels = $this->signals->inboxChannels($conversation, 'deleted', null);

        if ($conversation->assigned_user_id !== null) {
            $inboxChannels[] = RealtimeChannels::user($conversation->assigned_user_id);
        }

        $conversationId = $conversation->id;

        /** @var array{summary: array{messages: int, attachments: int}, files: list<array{disk: string, path: string}>, audit: int} $result */
        $result = DB::transaction(function () use ($conversationId, $actor): array {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversationId);

            $messageIds = DB::table('messages')->where('conversation_id', $locked->id)->select('id');

            $files = DB::table('message_attachments')
                ->whereIn('message_id', $messageIds)
                ->whereNotNull('path')
                ->where('path', '!=', '')
                ->get(['disk', 'path'])
                ->map(fn (object $file): array => ['disk' => (string) $file->disk, 'path' => (string) $file->path])
                ->unique(fn (array $file): string => $file['disk'].'|'.$file['path'])
                ->values()
                ->all();

            $messages = DB::table('messages')->where('conversation_id', $locked->id)->count();
            $attachments = DB::table('message_attachments')->whereIn('message_id', $messageIds)->count();
            $notes = DB::table('conversation_notes')->where('conversation_id', $locked->id)->count();

            /** @var Contact|null $contact */
            $contact = $locked->contact()->first();
            $connectionName = $locked->channelConnection()->value('name');

            // Idempotency keys of the Whaticket importer (conversation and
            // messages), so a later import can recreate what is erased here.
            DB::table('import_source_keys')
                ->where('target_type', 'message')
                ->whereIn('target_id', $messageIds)
                ->delete();
            DB::table('import_source_keys')
                ->where('target_type', $locked->getMorphClass())
                ->where('target_id', $locked->id)
                ->delete();

            DB::table('notifications')->where('data->conversation_id', $locked->id)->delete();

            DB::table('message_attachments')->whereIn('message_id', $messageIds)->delete();
            DB::table('messages')->where('conversation_id', $locked->id)->update(['reply_to_message_id' => null]);
            DB::table('messages')->where('conversation_id', $locked->id)->delete();
            DB::table('conversation_notes')->where('conversation_id', $locked->id)->delete();
            DB::table('conversation_events')->where('conversation_id', $locked->id)->delete();

            // Everything else hangs from the conversation with ON DELETE
            // CASCADE (metrics, agent metrics, response cycles, read marks,
            // tags, per-chat appearances) or SET NULL (contact attributions,
            // the staging row of the history importer).
            DB::table('conversations')->where('id', $locked->id)->delete();

            $entry = $this->audit->record(AuditAction::ConversationPermanentlyDeleted, $contact, [
                'conversation_id' => $locked->id,
                'contact' => $contact?->name,
                'connection' => $connectionName,
                'status' => $locked->status->value,
                'origin' => $locked->origin->value,
                'messages' => $messages,
                'attachments' => $attachments,
                'notes' => $notes,
                'files' => count($files),
            ], $actor);

            return [
                'summary' => ['messages' => $messages, 'attachments' => $attachments],
                'files' => $files,
                'audit' => (int) $entry->getKey(),
            ];
        });

        [$deleted, $pending] = $this->removeFiles($result['files']);

        if ($pending > 0) {
            // The entry was written inside the transaction; it only learns
            // afterwards how many files stayed on the storage.
            $entry = AuditLog::query()->find($result['audit']);
            $entry?->forceFill(['properties' => [...($entry->properties ?? []), 'files_pending' => $pending]])->save();
        }

        $this->signals->send([RealtimeChannels::conversation($conversationId)], 'conversation.changed', [
            'reason' => 'deleted',
            'version' => $conversation->version,
        ]);
        $this->signals->send(array_values(array_unique($inboxChannels)), 'inbox.changed', [
            'conversation_id' => $conversationId,
            'reason' => 'deleted',
        ]);

        return [...$result['summary'], 'files_deleted' => $deleted, 'files_pending' => $pending];
    }

    /**
     * Remove each file with its previews, unless another attachment (of a
     * conversation that stays) still uses the same stored file.
     *
     * @param  list<array{disk: string, path: string}>  $files
     * @return array{0: int, 1: int} Files removed and files left on the storage.
     */
    private function removeFiles(array $files): array
    {
        $deleted = 0;
        $pending = 0;

        foreach ($files as $file) {
            if (DB::table('message_attachments')->where('disk', $file['disk'])->where('path', $file['path'])->exists()) {
                continue;
            }

            // A share that is not mounted leaves an empty local directory:
            // deleting there would "succeed" without touching the real file.
            if ($this->mediaDisk->isMount($file['disk']) && $this->mounts->cheapProblem($file['disk']) !== null) {
                $pending++;

                Log::warning('A file of a permanently deleted conversation stays on a storage that is not available.', [
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                ]);

                continue;
            }

            try {
                $removed = Storage::disk($file['disk'])->delete([$file['path'], ...MediaThumbnails::pathsFor($file['path'])]);
            } catch (Throwable $exception) {
                $removed = false;

                Log::warning('A file of a permanently deleted conversation could not be removed from the storage.', [
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'exception' => $exception::class,
                ]);
            }

            if ($removed && ! $this->stillThere($file)) {
                $deleted++;
            } else {
                $pending++;
            }
        }

        return [$deleted, $pending];
    }

    /**
     * @param  array{disk: string, path: string}  $file
     */
    private function stillThere(array $file): bool
    {
        try {
            return Storage::disk($file['disk'])->exists($file['path']);
        } catch (Throwable) {
            return true;
        }
    }
}
