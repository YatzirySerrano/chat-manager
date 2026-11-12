<?php

namespace App\Actions\TeamChat;

use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\User;
use App\Services\Storage\MediaDisk;
use Illuminate\Support\Facades\DB;

/**
 * The author deletes their message: its text and files are erased for
 * everyone (nobody, administrators included, can recover them later) and
 * the row stays as a "Mensaje eliminado" marker so the thread keeps its
 * order and replies. Its mentions and reactions go with the content. Files are removed after the commit from the
 * disk recorded on each attachment (older files may live on the legacy TEAM_CHAT_DISK), best effort: a storage
 * failure is logged and never undoes the deletion.
 */
class DeleteTeamMessage
{
    public function __construct(private MediaDisk $mediaDisk) {}

    public function handle(TeamMessage $message, User $actor): void
    {
        $files = [];

        DB::transaction(function () use ($message, $actor, &$files): void {
            $message->forceFill(['body' => null, 'metadata' => null, 'deleted_at' => now()])->save();
            $message->reactions()->delete();

            foreach ($message->attachments()->whereNull('purged_at')->get() as $attachment) {
                /** @var TeamAttachment $attachment */
                if ($attachment->path !== null) {
                    $files[] = [$attachment->disk, $attachment->path];
                }

                $attachment->forceFill(['path' => null, 'purged_at' => now()])->save();
            }

            TeamThreadChanged::dispatch($message->team_thread_id, TeamThreadChanged::MESSAGE_DELETED, $actor->id, [], $message->id);
        });

        foreach ($files as [$disk, $path]) {
            $this->mediaDisk->discard($disk, $path);
        }
    }
}
