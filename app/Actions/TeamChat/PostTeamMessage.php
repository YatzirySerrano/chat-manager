<?php

namespace App\Actions\TeamChat;

use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Services\TeamChat\TeamAttachmentInspector;
use App\Services\TeamChat\TeamThreadActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Send a message (text and/or files) to an internal thread. Files were
 * validated by their real content; they are stored on the private disk
 * under a random name before the transaction, and removed again if it
 * fails, so no orphan rows or files remain. The author's own message counts
 * as read for them. Internal messages never reach Meta.
 */
class PostTeamMessage
{
    public function __construct(
        private TeamThreadActivity $activity,
        private TeamAttachmentInspector $inspector,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function handle(TeamThread $thread, User $author, ?string $body, ?int $replyToMessageId, array $files = []): TeamMessage
    {
        $disk = (string) config('team-chat.attachments.disk');
        $stored = [];

        try {
            foreach ($files as $file) {
                $mime = $this->inspector->acceptedMimeType($file);

                if ($mime === null) {
                    throw new RuntimeException('An attachment that was not validated reached PostTeamMessage.');
                }

                $path = $file->storeAs('team-chat/'.now()->format('Y/m'), (string) Str::uuid(), ['disk' => $disk]);

                if ($path === false) {
                    throw new RuntimeException('The attachment could not be stored.');
                }

                $stored[] = [
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $this->inspector->safeName($file),
                    'mime_type' => $mime,
                    'kind' => $this->inspector->kindOf($mime),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
                ];
            }

            return DB::transaction(function () use ($thread, $author, $body, $replyToMessageId, $stored): TeamMessage {
                $message = new TeamMessage([
                    'user_id' => $author->id,
                    'body' => $body,
                    'reply_to_message_id' => $replyToMessageId,
                ]);
                $message->team_thread_id = $thread->id;
                $message->save();

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
            foreach ($stored as $attributes) {
                Storage::disk($attributes['disk'])->delete($attributes['path']);
            }

            throw $exception;
        }
    }
}
