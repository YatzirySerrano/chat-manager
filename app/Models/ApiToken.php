<?php

namespace App\Models;

use App\Enums\ApiAbility;
use App\Enums\ConversationOrigin;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Token of the server-to-server integration API. The token is the principal:
 * it carries its abilities and the connections (and, optionally, the
 * departments) whose conversations it may reach. The plain token is
 * 'cm_live_{public_id}_{secret}' and only the SHA-256 of the secret is
 * stored; it always expires and can be revoked.
 *
 * @property int $id
 * @property string $public_id Lowercase ULID.
 * @property string $name
 * @property string $token_hash
 * @property list<string> $abilities ApiAbility values.
 * @property list<int> $connection_ids
 * @property list<int>|null $department_ids
 * @property list<string>|null $allowed_ips IPs or CIDR ranges (v4/v6); null allows any.
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property int|null $created_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoke_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'abilities', 'connection_ids', 'department_ids', 'allowed_ips', 'expires_at', 'created_by'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    public const TOKEN_PREFIX = 'cm_live_';

    private const TOKEN_PATTERN = '/^cm_live_([0-9a-z]{26})_([A-Za-z0-9]{40})$/';

    /**
     * Every token gets its public identifier when created.
     */
    protected static function booted(): void
    {
        static::creating(function (ApiToken $token): void {
            $token->public_id ??= strtolower((string) Str::ulid());
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'connection_ids' => 'array',
            'department_ids' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Issues the secret of the token (not saved): only its hash is kept, so
     * the plain token must be shown right away and never again.
     */
    public function generateToken(): string
    {
        $this->public_id ??= strtolower((string) Str::ulid());

        $secret = Str::random(40);
        $this->token_hash = hash('sha256', $secret);

        return self::TOKEN_PREFIX.$this->public_id.'_'.$secret;
    }

    /**
     * Token the plain value belongs to, or null when it is malformed, unknown
     * or its secret does not match (compared in constant time). Usability is
     * checked separately with isUsable().
     */
    public static function findByToken(string $plain): ?self
    {
        if (preg_match(self::TOKEN_PATTERN, $plain, $matches) !== 1) {
            return null;
        }

        $token = self::query()->where('public_id', $matches[1])->first();

        if ($token === null || ! hash_equals($token->token_hash, hash('sha256', $matches[2]))) {
            return null;
        }

        return $token;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function allows(ApiAbility $ability): bool
    {
        return in_array($ability->value, $this->abilities, true);
    }

    /**
     * Whether the request IP is inside the allowlist (IPs or CIDR ranges,
     * IPv4 or IPv6). A token without allowlist accepts any IP.
     */
    public function allowsIp(string $ip): bool
    {
        if ($this->allowed_ips === null || $this->allowed_ips === []) {
            return true;
        }

        return IpUtils::checkIp($ip, $this->allowed_ips);
    }

    /**
     * Whether the token reaches this (freshly read) conversation: one of its
     * connections and, when it lists them, one of its departments. The
     * connection's deletion is checked by whoever sends through it.
     */
    public function reaches(Conversation $conversation): bool
    {
        // Imported history (Whaticket) is never reachable by integrations.
        if ($conversation->isImported()) {
            return false;
        }

        if (! in_array($conversation->connection_id, array_map('intval', $this->connection_ids), true)) {
            return false;
        }

        return blank($this->department_ids)
            || in_array($conversation->department_id, array_map('intval', $this->department_ids), true);
    }

    /**
     * Restricts a conversation query to what the token may reach: its
     * connections that still exist (deleting a connection cuts the
     * integration off from its conversations, as /health stops listing it)
     * and, when it lists them, its departments.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeConversations(Builder $query): Builder
    {
        // Imported history (Whaticket) is never exposed to integrations.
        $query->where($query->qualifyColumn('origin'), ConversationOrigin::Live->value);

        $query->whereIn($query->qualifyColumn('connection_id'), Connection::query()
            ->select('id')
            ->whereKey(array_map('intval', $this->connection_ids))
            ->notDeleted());

        if (filled($this->department_ids)) {
            $query->whereIn($query->qualifyColumn('department_id'), array_map('intval', $this->department_ids));
        }

        return $query;
    }
}
