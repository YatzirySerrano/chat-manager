<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeQuickReplyStatus
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Inactive replies keep their shortcut reserved, so reactivating one never
     * creates a conflict.
     */
    public function handle(QuickReply $quickReply, bool $active, User $actor): QuickReply
    {
        if ($quickReply->is_active === $active) {
            return $quickReply;
        }

        return DB::transaction(function () use ($quickReply, $active, $actor): QuickReply {
            $quickReply->forceFill(['is_active' => $active, 'updated_by_user_id' => $actor->id])->save();

            $this->audit->record(
                $active ? AuditAction::QuickReplyActivated : AuditAction::QuickReplyDeactivated,
                $quickReply,
                [],
                $actor,
            );

            return $quickReply;
        });
    }
}
