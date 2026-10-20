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
     * @param  array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>}  $data
     */
    public function handle(QuickReply $quickReply, array $data, User $actor): QuickReply
    {
        return DB::transaction(function () use ($quickReply, $data, $actor): QuickReply {
            // Serializes concurrent edits of this reply, even when only its departments change.
            QuickReply::query()->whereKey($quickReply->id)->lockForUpdate()->value('id');

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
                return $quickReply;
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

            return $quickReply;
        }, 3);
    }
}
