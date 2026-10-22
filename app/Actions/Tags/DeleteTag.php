<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Logically deletes a tag: it can no longer be assigned, while contacts and
 * conversations that already carry it keep it as history.
 */
class DeleteTag
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Tag $tag, User $actor): Tag
    {
        return DB::transaction(function () use ($tag, $actor): Tag {
            /** @var Tag $locked */
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->audit->record(AuditAction::TagDeleted, $locked, [
                'contacts_kept' => $locked->contacts()->count(),
                'conversations_kept' => $locked->conversations()->count(),
            ], $actor);

            return $locked;
        });
    }
}
