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
     * A personal reply belongs to the actor: it is never global and has no
     * departments.
     *
     * @param  array{title: string, shortcut: string, body: string, is_global: bool, department_ids: list<int>, personal?: bool}  $data
     */
    public function handle(array $data, User $actor): QuickReply
    {
        // Retried when two transactions deadlock over the same shortcut gap.
        return DB::transaction(function () use ($data, $actor): QuickReply {
            $isPersonal = $data['personal'] ?? false;
            $isGlobal = ! $isPersonal && $data['is_global'];
            $departmentIds = $isPersonal || $isGlobal ? [] : $data['department_ids'];

            if ($isPersonal) {
                $this->ensureShortcutIsAvailable->handlePersonal($actor, $data['shortcut']);
            } else {
                $this->ensureShortcutIsAvailable->handle($actor, $data['shortcut'], $isGlobal, $departmentIds);
            }

            $quickReply = new QuickReply([
                'title' => $data['title'],
                'shortcut' => $data['shortcut'],
                'body' => $data['body'],
                'is_global' => $isGlobal,
            ]);
            $quickReply->owner_user_id = $isPersonal ? $actor->id : null;
            $quickReply->created_by_user_id = $actor->id;
            $quickReply->updated_by_user_id = $actor->id;
            $quickReply->save();

            $quickReply->departments()->attach($departmentIds);

            $this->audit->record(AuditAction::QuickReplyCreated, $quickReply, $isPersonal ? [
                'shortcut' => $quickReply->shortcut,
                'personal' => true,
            ] : [
                'shortcut' => $quickReply->shortcut,
                'is_global' => $quickReply->is_global,
                'departments' => Department::query()->whereKey($departmentIds)->orderBy('name')->pluck('name')->all(),
            ], $actor);

            return $quickReply;
        }, 3);
    }
}
