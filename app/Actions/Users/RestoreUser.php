<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Restores a logically deleted account: it can sign in again with its role
 * and departments. Conversations released when it was deleted stay where
 * they are; nothing is reassigned automatically.
 */
class RestoreUser
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $locked->isDeleted()) {
                return $locked;
            }

            $deletedAt = $locked->deactivated_at;

            $locked->forceFill(['is_active' => true, 'deactivated_at' => null])->save();

            $this->audit->record(AuditAction::UserRestored, $locked, [
                'deleted_at' => $deletedAt?->toIso8601String(),
            ], $actor);

            return $locked;
        });
    }
}
