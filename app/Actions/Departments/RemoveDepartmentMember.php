<?php

namespace App\Actions\Departments;

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\AuditAction;
use App\Events\Users\UserAccessChanged;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveDepartmentMember
{
    public function __construct(
        private AuditLogger $audit,
        private ReleaseAssignedConversations $releaseConversations,
    ) {}

    public function handle(Department $department, User $member, User $actor): void
    {
        DB::transaction(function () use ($department, $member, $actor): void {
            if ($department->members()->detach($member->id) === 0) {
                throw ValidationException::withMessages(['user_id' => 'El usuario no pertenece a este departamento.']);
            }

            $released = $this->releaseConversations->handle($member, $department->id, $actor, 'Retirado del departamento');

            $this->audit->record(AuditAction::DepartmentMemberRemoved, $department, [
                'member' => $member->name,
                'member_id' => $member->id,
                'released_conversations' => $released,
            ], $actor);

            UserAccessChanged::dispatch([$member->id], 'department_member_removed');
        });
    }
}
