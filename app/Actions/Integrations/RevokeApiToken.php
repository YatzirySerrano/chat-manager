<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Revokes an integration API token right away: the next request made with
 * it is rejected (tokens are read from the database on every request). A
 * token already revoked is left as it was.
 */
class RevokeApiToken
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return bool Whether the token was revoked now (false when it already was).
     */
    public function handle(ApiToken $token, User $actor, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($token, $actor, $reason): bool {
            $locked = ApiToken::query()->lockForUpdate()->findOrFail($token->id);

            if ($locked->revoked_at !== null) {
                return false;
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => $reason,
            ])->save();

            $this->audit->record(AuditAction::ApiTokenRevoked, $locked, [
                'public_id' => $locked->public_id,
                'name' => $locked->name,
                'reason' => $reason,
            ], $actor);

            $token->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
