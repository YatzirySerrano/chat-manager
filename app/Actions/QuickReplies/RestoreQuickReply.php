<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted quick reply with its reserved shortcut. The
 * shortcut of a deleted personal reply is not reserved (its owner may reuse
 * it), so restoring one fails with a validation error when the owner already
 * has another active reply with that shortcut.
 */
class RestoreQuickReply
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureShortcutIsAvailable $ensureShortcutIsAvailable,
    ) {}

    public function handle(QuickReply $quickReply, User $actor): QuickReply
    {
        return DB::transaction(function () use ($quickReply, $actor): QuickReply {
            /** @var QuickReply $locked */
            $locked = QuickReply::query()->lockForUpdate()->findOrFail($quickReply->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            if ($locked->isPersonal() && $locked->owner !== null) {
                $this->ensureShortcutIsAvailable->handlePersonal($locked->owner, $locked->shortcut, $locked->id);
            }

            $locked->forceFill(['is_active' => true, 'updated_by_user_id' => $actor->id])->save();

            $this->audit->record(AuditAction::QuickReplyRestored, $locked, $locked->isPersonal() ? ['personal' => true, 'owner_user_id' => $locked->owner_user_id] : [], $actor);

            return $locked;
        });
    }
}
