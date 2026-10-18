<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Permanently deletes an account that has no operational history.
 * Accounts with history must be deactivated to preserve traceability.
 */
class DeleteUser
{
    public function __construct(
        private AuditLogger $audit,
        private EnsureRootAuthorityRemains $ensureRootAuthorityRemains,
    ) {}

    public function handle(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar tu propia cuenta desde administración.']);
        }

        DB::transaction(function () use ($user, $actor): void {
            $this->ensureRootAuthorityRemains->handle($user);

            if ($this->hasOperationalHistory($user)) {
                throw ValidationException::withMessages([
                    'user' => 'El usuario tiene historial operativo. Desactívalo en lugar de eliminarlo para conservar la trazabilidad.',
                ]);
            }

            $this->audit->record(AuditAction::UserDeleted, $user, [
                'role' => $user->role()?->label,
            ], $actor);

            $user->delete();
        });
    }

    private function hasOperationalHistory(User $user): bool
    {
        return Message::query()->where('sender_user_id', $user->id)->exists()
            || Conversation::query()->where('assigned_user_id', $user->id)->exists()
            || ConversationEvent::query()
                ->where(fn ($query) => $query->where('actor_user_id', $user->id)
                    ->orWhere('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id))
                ->exists();
    }
}
