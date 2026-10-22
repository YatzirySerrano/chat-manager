<?php

namespace App\Actions\TeamChat;

use App\Events\TeamChat\TeamThreadChanged;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The author deletes their message: its text and files are erased for
 * everyone (nobody, administrators included, can recover them later) and
 * the row stays as a "Mensaje eliminado" marker so the thread keeps its
 * order and replies. Files are removed from the disk after the commit.
 */
class DeleteTeamMessage
{
    public function handle(TeamMessage $message, User $actor): void
    {
        $files = [];

        DB::transaction(function () use ($message, $actor, &$files): void {
            $message->forceFill(['body' => null, 'metadata' => null, 'deleted_at' => now()])->save();

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
            Storage::disk($disk)->delete($path);
        }
    }
}
