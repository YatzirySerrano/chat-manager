<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Logically deletes a quick reply: nobody can insert it anymore and its
 * shortcut stays reserved (see EnsureShortcutIsAvailable), so restoring it
 * never creates a conflict. A personal reply deleted by someone other than its
 * owner (supervision) is recorded as SupervisionPersonalItemDeleted, naming
 * the owner and the title but never the text.
 */
class DeleteQuickReply
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(QuickReply $quickReply, User $actor): QuickReply
    {
        return DB::transaction(function () use ($quickReply, $actor): QuickReply {
            /** @var QuickReply $locked */
            $locked = QuickReply::query()->lockForUpdate()->findOrFail($quickReply->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false, 'updated_by_user_id' => $actor->id])->save();

            if ($locked->isPersonal() && $locked->owner_user_id !== $actor->id) {
                $this->audit->record(AuditAction::SupervisionPersonalItemDeleted, $locked, [
                    'owner_user_id' => $locked->owner_user_id,
                    'item_type' => 'quick_reply',
                    'item_id' => $locked->id,
                    'title' => $locked->title,
                ], $actor);
            } else {
                $this->audit->record(AuditAction::QuickReplyDeleted, $locked, $locked->isPersonal() ? ['personal' => true] : [], $actor);
            }

            return $locked;
        });
    }
}
