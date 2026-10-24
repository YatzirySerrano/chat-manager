<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues an integration API token. Only the hash of its secret is stored:
 * the plain token is returned once, for the person who issued it, and is
 * never written to the audit log.
 */
class IssueApiToken
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, abilities: list<string>, connection_ids: list<int>, department_ids: list<int>|null, allowed_ips: list<string>|null, expires_at: CarbonInterface}  $attributes
     * @return array{token: ApiToken, plain: string}
     */
    public function handle(array $attributes, User $actor): array
    {
        return DB::transaction(function () use ($attributes, $actor): array {
            $token = new ApiToken([...$attributes, 'created_by' => $actor->id]);
            $plain = $token->generateToken();
            $token->save();

            $this->audit->record(AuditAction::ApiTokenIssued, $token, [
                'public_id' => $token->public_id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'connection_ids' => $token->connection_ids,
                'department_ids' => $token->department_ids,
                'allowed_ips' => $token->allowed_ips,
                'expires_at' => $token->expires_at->toIso8601String(),
            ], $actor);

            return ['token' => $token, 'plain' => $plain];
        });
    }
}
