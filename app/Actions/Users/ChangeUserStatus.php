<?php

namespace App\Actions\Users;

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeUserStatus
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureRootAuthorityRemains $ensureRootAuthorityRemains,
        private ReleaseAssignedConversations $releaseConversations,
    ) {}

    public function handle(User $user, bool $active, User $actor): User
    {
        if ($user->is_active === $active) {
            return $user;
        }

        return DB::transaction(function () use ($user, $active, $actor): User {
            if ($active) {
                $user->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
                $this->audit->record(AuditAction::UserActivated, $user, [], $actor);

                return $user;
            }

            if ($user->is($actor)) {
                throw ValidationException::withMessages(['user' => 'No puedes desactivar tu propia cuenta.']);
            }

            $this->ensureRootAuthorityRemains->handle($user);

            $user->forceFill(['is_active' => false, 'deactivated_at' => now(), 'remember_token' => null])->save();

            $released = $this->releaseConversations->handle($user, null, $actor, 'Usuario desactivado');

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $this->audit->record(AuditAction::UserDeactivated, $user, ['released_conversations' => $released], $actor);

            return $user;
        });
    }
}
