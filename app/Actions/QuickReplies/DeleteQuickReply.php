<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteQuickReply
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Messages already sent are plain text and never reference the reply, so
     * deleting it leaves no history behind. The audit entry keeps its content.
     */
    public function handle(QuickReply $quickReply, User $actor): void
    {
        DB::transaction(function () use ($quickReply, $actor): void {
            $this->audit->record(AuditAction::QuickReplyDeleted, $quickReply, [
                'title' => $quickReply->title,
                'shortcut' => $quickReply->shortcut,
                'is_global' => $quickReply->is_global,
                'departments' => $quickReply->departments()->orderBy('name')->pluck('name')->all(),
                'body' => $quickReply->body,
            ], $actor);

            $quickReply->delete();
        });
    }
}
