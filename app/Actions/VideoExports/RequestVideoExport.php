<?php

namespace App\Actions\VideoExports;

use App\Enums\AuditAction;
use App\Enums\VideoExportStatus;
use App\Jobs\SaveVideoToNas;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\TeamChat\TeamAttachment;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use App\Models\VideoExport;
use App\Services\AuditLogger;
use App\Services\VideoExports\VideoExports;
use App\Services\VideoExports\VideoFolders;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records a «Guardar video» request (a VideoExport with the chosen folder
 * and file name), audits it and queues the copy (SaveVideoToNas) once
 * committed. A video whose previous copy is still on its way is refused
 * (409), so a double click does not write it twice. The caller has already
 * checked the permission, the access to the chat and that the folder exists.
 */
class RequestVideoExport
{
    public function __construct(
        private VideoFolders $folders,
        private VideoExports $videoExports,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $folder  Chosen folder, relative to the root of saved videos.
     * @param  string  $fileName  Clean file name, without extension.
     */
    public function handle(User $actor, MessageAttachment|TeamAttachment $attachment, Conversation|TeamThread $chat, array $folder, string $fileName): VideoExport
    {
        $column = $attachment instanceof MessageAttachment ? 'message_attachment_id' : 'team_attachment_id';

        $export = DB::transaction(function () use ($actor, $attachment, $chat, $folder, $fileName, $column): VideoExport {
            // Serializes two requests for the same video.
            $attachment->newQuery()->whereKey($attachment->getKey())->lockForUpdate()->first();

            $pending = VideoExport::query()
                ->where($column, $attachment->getKey())
                ->whereIn('status', [VideoExportStatus::Queued->value, VideoExportStatus::Saving->value])
                ->exists();

            if ($pending) {
                throw new ConflictHttpException('Este video ya se está guardando en Synology.');
            }

            $export = new VideoExport;
            $export->forceFill([
                $column => $attachment->getKey(),
                'folder' => implode('/', $folder),
                'file_name' => $fileName,
                'disk' => $this->folders->disk(),
                'status' => VideoExportStatus::Queued,
                'user_id' => $actor->id,
            ])->save();

            $this->audit->record(AuditAction::VideoSaveRequested, $chat, [
                'video_export_id' => $export->id,
                'attachment_id' => $attachment->getKey(),
                'source' => $column === 'message_attachment_id' ? 'conversation' : 'team_chat',
                'folder' => $this->folders->pathOnDisk($folder),
                'file_name' => $fileName,
            ], $actor);

            SaveVideoToNas::dispatch($export->id)->afterCommit();

            return $export;
        });

        $this->videoExports->flush();

        return $export;
    }
}
