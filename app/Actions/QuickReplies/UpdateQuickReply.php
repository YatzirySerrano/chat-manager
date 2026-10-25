<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateQuickReply
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureShortcutIsAvailable $ensureShortcutIsAvailable,
    ) {}

    /**
     * Nothing is written (no save, no department sync, no audit entry) when
     * the data and the set of departments stay the same.
     *
     * A personal reply keeps its owner, stays non-global and without
     * departments. When someone other than the owner edits it (supervision)
     * the entry is SupervisionPersonalItemUpdated, which names the owner and
     * the title but never the text.
     *
     * @param  array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>, personal?: bool}  $data
     * @return bool Whether anything changed.
     */
    public function handle(QuickReply $quickReply, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($quickReply, $data, $actor): bool {
            // Serializes concurrent edits of this reply, even when only its departments change.
            QuickReply::query()->whereKey($quickReply->id)->lockForUpdate()->value('id');

            if ($quickReply->isPersonal()) {
                return $this->updatePersonal($quickReply, $data, $actor);
            }

            $departmentIds = $data['is_global'] ? [] : $data['department_ids'];
            sort($departmentIds);

            $currentDepartmentIds = array_map('intval', $quickReply->departments()->pluck('departments.id')->all());
            sort($currentDepartmentIds);

            $quickReply->fill([
                'title' => $data['title'],
                'shortcut' => $data['shortcut'],
                'body' => $data['body'],
                'is_global' => $data['is_global'],
            ]);

            $departmentsChanged = $departmentIds !== $currentDepartmentIds;

            if (! $quickReply->isDirty() && ! $departmentsChanged) {
                return false;
            }

            if ($departmentsChanged || $quickReply->isDirty(['shortcut', 'is_global'])) {
                $this->ensureShortcutIsAvailable->handle($actor, $data['shortcut'], $data['is_global'], $departmentIds, $quickReply->id);
            }

            $changes = [];

            foreach (['title', 'shortcut', 'is_global'] as $attribute) {
                if ($quickReply->isDirty($attribute)) {
                    $changes[$attribute] = ['old' => $quickReply->getOriginal($attribute), 'new' => $quickReply->getAttribute($attribute)];
                }
            }

            if ($quickReply->isDirty('body')) {
                $changes['body'] = ['changed' => true];
            }

            if ($departmentsChanged) {
                $names = Department::query()
                    ->whereKey(array_merge($departmentIds, $currentDepartmentIds))
                    ->pluck('name', 'id');

                $changes['departments'] = [
                    'old' => $names->only($currentDepartmentIds)->sort()->values()->all(),
                    'new' => $names->only($departmentIds)->sort()->values()->all(),
                ];
            }

            $quickReply->updated_by_user_id = $actor->id;
            $quickReply->save();

            if ($departmentsChanged) {
                $quickReply->departments()->sync($departmentIds);
            }

            $this->audit->record(AuditAction::QuickReplyUpdated, $quickReply, ['changes' => $changes], $actor);

            return true;
        }, 3);
    }

    /**
     * @param  array{title: string, shortcut: string, body: string}  $data
     */
    private function updatePersonal(QuickReply $quickReply, array $data, User $actor): bool
    {
        $quickReply->fill([
            'title' => $data['title'],
            'shortcut' => $data['shortcut'],
            'body' => $data['body'],
        ]);

        if (! $quickReply->isDirty()) {
            return false;
        }

        /** @var User $owner */
        $owner = $quickReply->owner;

        if ($quickReply->isDirty('shortcut')) {
            $this->ensureShortcutIsAvailable->handlePersonal($owner, $data['shortcut'], $quickReply->id);
        }

        $changes = [];

        foreach (['title', 'shortcut'] as $attribute) {
            if ($quickReply->isDirty($attribute)) {
                $changes[$attribute] = ['old' => $quickReply->getOriginal($attribute), 'new' => $quickReply->getAttribute($attribute)];
            }
        }

        if ($quickReply->isDirty('body')) {
            $changes['body'] = ['changed' => true];
        }

        $quickReply->updated_by_user_id = $actor->id;
        $quickReply->save();

        if ($owner->is($actor)) {
            $this->audit->record(AuditAction::QuickReplyUpdated, $quickReply, ['personal' => true, 'changes' => $changes], $actor);
        } else {
            $this->audit->record(AuditAction::SupervisionPersonalItemUpdated, $quickReply, [
                'owner_user_id' => $owner->id,
                'item_type' => 'quick_reply',
                'item_id' => $quickReply->id,
                'title' => $quickReply->title,
                'changes' => $changes,
            ], $actor);
        }

        return true;
    }
}
