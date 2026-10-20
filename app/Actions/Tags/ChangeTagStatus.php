<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeTagStatus
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Deactivating a tag only prevents new assignments; records that already
     * carry it keep it.
     */
    public function handle(Tag $tag, bool $active, User $actor): Tag
    {
        if ($tag->is_active === $active) {
            return $tag;
        }

        return DB::transaction(function () use ($tag, $active, $actor): Tag {
            $tag->forceFill(['is_active' => $active])->save();

            $this->audit->record(
                $active ? AuditAction::TagActivated : AuditAction::TagDeactivated,
                $tag,
                [],
                $actor,
            );

            return $tag;
        });
    }
}
