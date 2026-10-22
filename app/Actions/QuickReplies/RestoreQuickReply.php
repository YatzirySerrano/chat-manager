<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted quick reply with its reserved shortcut.
 */
class RestoreQuickReply
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(QuickReply $quickReply, User $actor): QuickReply
    {
        return DB::transaction(function () use ($quickReply, $actor): QuickReply {
            /** @var QuickReply $locked */
            $locked = QuickReply::query()->lockForUpdate()->findOrFail($quickReply->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => true, 'updated_by_user_id' => $actor->id])->save();

            $this->audit->record(AuditAction::QuickReplyRestored, $locked, [], $actor);

            return $locked;
        });
    }
}
