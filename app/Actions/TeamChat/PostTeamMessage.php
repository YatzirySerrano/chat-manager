<?php

namespace App\Actions\TeamChat;

use App\Exceptions\StorageUnavailable;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\Storage\MediaDisk;
use App\Services\TeamChat\TeamAttachmentInspector;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Send a message (text and/or files) to an internal thread. Files were
 * validated by their real content; they are stored on the media disk
 * (MediaDisk: MEDIA_DISK, verified writes, the disk recorded per file,
 * StorageUnavailable when it is not ready) under a random name before the
 * transaction, and removed again if it fails, so no orphan rows or files
 * remain. The author's own message counts as read for them. Internal
 * messages never reach Meta.
 *
 * Idempotent: the browser sends a `client_reference` (UUID) per message and
 * keeps it while it retries. A retry of a message already stored (its
 * response was lost) answers that message: nothing is stored, broadcast or
 * notified again, and its files are not written a second time. The
 * returned message's `wasRecentlyCreated` tells both cases apart.
 *
 * Permission to post was checked by the request, but a removal, a departure
 * or an archive may commit in between: the check is repeated here while
 * holding the thread row, the same lock those operations take, so a message
 * never lands after the author lost access or the group became read-only.
 * Mentions keep only current participants of the group other than the
 * author (nobody else is ever notified).
 */
class PostTeamMessage
{
    public function __construct(
        private TeamThreadActivity $activity,
        private TeamAttachmentInspector $inspector,
        private MediaDisk $mediaDisk,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $mentionedUserIds
     *
     * @throws AuthorizationException when the author may no longer post.
     * @throws StorageUnavailable when the media disk cannot store the files.
     * @throws ValidationException when the reference belongs to another thread.
     */
    public function handle(TeamThread $thread, User $author, ?string $body, ?int $replyToMessageId, array $files = [], array $mentionedUserIds = [], ?string $clientReference = null): TeamMessage
    {
        $stored = [];

        // A retry of a stored message: its files are not written again.
        if ($files !== [] && $this->existing($author, $clientReference) !== null) {
            $files = [];
        }

        if ($files !== []) {
            $this->mediaDisk->assertReady();
        }

        try {
            foreach ($files as $file) {
                $mime = $this->inspector->acceptedMimeType($file);
                $localPath = $file->getRealPath();

                if ($mime === null || $localPath === false) {
                    throw new RuntimeException('An attachment that was not validated reached PostTeamMessage.');
                }

                $location = $this->mediaDisk->putFile($localPath, 'team-chat/'.now()->format('Y/m'));

                $stored[] = [
                    'disk' => $location['disk'],
                    'path' => $location['path'],
                    'original_name' => $this->inspector->safeName($file),
                    'mime_type' => $mime,
                    'kind' => $this->inspector->kindOf($mime),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => (string) hash_file('sha256', $localPath),
                ];
            }

            $message = DB::transaction(function () use ($thread, $author, $body, $replyToMessageId, $stored, $mentionedUserIds, $clientReference): TeamMessage {
                $locked = TeamThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($author)->authorize('post', $locked);

                $existing = $this->existing($author, $clientReference);

                if ($existing !== null) {
                    $this->assertSameThread($existing, $thread);

                    return $existing;
                }

                $mentions = $this->mentionableAmong($locked, $author, $mentionedUserIds);

                $message = new TeamMessage([
                    'user_id' => $author->id,
                    'body' => $body,
                    'reply_to_message_id' => $replyToMessageId,
                    'metadata' => $mentions === [] ? null : ['mentions' => $mentions],
                ]);
                $message->team_thread_id = $thread->id;
                $message->client_reference = $clientReference;

                try {
                    $message->save();
                } catch (UniqueConstraintViolationException) {
                    // Only possible with the same reference in another thread
                    // (posts to one thread are serialized by the lock).
                    throw $this->referenceTaken();
                }

                foreach ($stored as $attributes) {
                    $attachment = new TeamAttachment($attributes);
                    $attachment->team_message_id = $message->id;
                    $attachment->save();
                }

                TeamParticipant::query()
                    ->where('team_thread_id', $thread->id)
                    ->where('user_id', $author->id)
                    ->update(['last_read_message_id' => $message->id]);

                $this->activity->messageAdded($thread, $message);

                return $message;
            });
        } catch (Throwable $exception) {
            $this->discard($stored);

            throw $exception;
        }

        // Stored meanwhile by the first attempt: the files of this one go.
        if (! $message->wasRecentlyCreated) {
            $this->discard($stored);
        }

        return $message;
    }

    /**
     * The author's message already stored with this reference, if any.
     */
    private function existing(User $author, ?string $clientReference): ?TeamMessage
    {
        if ($clientReference === null) {
            return null;
        }

        return TeamMessage::query()
            ->where('user_id', $author->id)
            ->where('client_reference', $clientReference)
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertSameThread(TeamMessage $existing, TeamThread $thread): void
    {
        if ($existing->team_thread_id !== $thread->id) {
            throw $this->referenceTaken();
        }
    }

    private function referenceTaken(): ValidationException
    {
        return ValidationException::withMessages([
            'client_reference' => 'La referencia del mensaje ya se usó en otro chat. Vuelve a enviarlo.',
        ]);
    }

    /**
     * @param  list<array{disk: string, path: string}|array<string, mixed>>  $stored
     */
    private function discard(array $stored): void
    {
        foreach ($stored as $attributes) {
            $this->mediaDisk->discard((string) $attributes['disk'], (string) $attributes['path']);
        }
    }

    /**
     * The requested ids that are current participants of the group, the
     * author excluded.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function mentionableAmong(TeamThread $thread, User $author, array $userIds): array
    {
        $userIds = array_values(array_diff(array_unique($userIds), [$author->id]));

        if ($userIds === [] || ! $thread->isGroup()) {
            return [];
        }

        return array_values(array_map('intval', TeamParticipant::query()
            ->where('team_thread_id', $thread->id)
            ->whereNull('left_at')
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->all()));
    }
}
