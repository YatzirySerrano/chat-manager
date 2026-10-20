<?php

namespace App\Actions\QuickReplies;

use App\Enums\AuditAction;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateQuickReply
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureShortcutIsAvailable $ensureShortcutIsAvailable,
    ) {}

    /**
     * @param  array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>}  $data
     */
    public function handle(array $data, User $actor): QuickReply
    {
        // Retried when two transactions deadlock over the same shortcut gap.
        return DB::transaction(function () use ($data, $actor): QuickReply {
            $departmentIds = $data['is_global'] ? [] : $data['department_ids'];

            $this->ensureShortcutIsAvailable->handle($actor, $data['shortcut'], $data['is_global'], $departmentIds);

            $quickReply = new QuickReply([
                'title' => $data['title'],
                'shortcut' => $data['shortcut'],
                'body' => $data['body'],
                'is_global' => $data['is_global'],
            ]);
            $quickReply->created_by_user_id = $actor->id;
            $quickReply->updated_by_user_id = $actor->id;
            $quickReply->save();

            $quickReply->departments()->attach($departmentIds);

            $this->audit->record(AuditAction::QuickReplyCreated, $quickReply, [
                'shortcut' => $quickReply->shortcut,
                'is_global' => $quickReply->is_global,
                'departments' => Department::query()->whereKey($departmentIds)->orderBy('name')->pluck('name')->all(),
            ], $actor);

            return $quickReply;
        }, 3);
    }
}
