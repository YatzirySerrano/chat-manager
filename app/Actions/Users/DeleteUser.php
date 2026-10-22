<?php

namespace App\Actions\Users;

use App\Actions\Conversations\ReleaseAssignedConversations;
use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logically deletes an account: it loses access at once (sessions and the
 * "remember me" token are discarded and EnsureUserIsActive signs it out),
 * its open conversations return to their department queue with an audited
 * event, and everything it did stays in the history. Nothing is removed.
 */
class DeleteUser
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureRootAuthorityRemains $ensureRootAuthorityRemains,
        private ReleaseAssignedConversations $releaseConversations,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, User $actor): User
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar tu propia cuenta.']);
        }

        return DB::transaction(function () use ($user, $actor): User {
            $this->ensureRootAuthorityRemains->handle($user);

            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->isDeleted()) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false, 'deactivated_at' => now(), 'remember_token' => null])->save();

            $released = $this->releaseConversations->handle($locked, null, $actor, 'Usuario eliminado');

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $locked->id)->delete();
            }

            $this->audit->record(AuditAction::UserDeleted, $locked, [
                'role' => $locked->role()?->label,
                'released_conversations' => $released,
            ], $actor);

            return $locked;
        });
    }
}
