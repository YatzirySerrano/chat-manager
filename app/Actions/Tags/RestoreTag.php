<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted tag so it can be assigned again.
 */
class RestoreTag
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Tag $tag, User $actor): Tag
    {
        return DB::transaction(function () use ($tag, $actor): Tag {
            /** @var Tag $locked */
            $locked = Tag::query()->lockForUpdate()->findOrFail($tag->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => true])->save();

            $this->audit->record(AuditAction::TagRestored, $locked, [], $actor);

            return $locked;
        });
    }
}
