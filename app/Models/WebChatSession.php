<?php

namespace App\Models;

use Database\Factories\WebChatSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Session of a web chat visitor. The bearer token is
 * 'wvs_{public_id}_{secret}' and only the SHA-256 of the secret is stored.
 * The contact and conversation exist only once the visitor writes; the
 * session then caches the contact. Expiry slides with use, up to an absolute
 * cap counted from its creation.
 *
 * @property int $id
 * @property string $public_id Lowercase ULID.
 * @property int $connection_id
 * @property string $visitor_id Contact identity of the visitor ('wv:…' anonymous, 'wi:…' identified).
 * @property bool $identified
 * @property string $token_hash
 * @property int|null $contact_id
 * @property array{name?: string|null, email?: string|null}|null $profile
 * @property string $origin Host origin validated when the session started.
 * @property string|null $landing_url
 * @property string|null $ip_hash HMAC of the IP (see hashIp).
 * @property string|null $user_agent
 * @property Carbon|null $last_seen_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'connection_id', 'visitor_id', 'identified', 'contact_id', 'profile', 'origin', 'landing_url', 'ip_hash',
    'user_agent', 'last_seen_at', 'expires_at',
])]
#[Hidden(['token_hash'])]
class WebChatSession extends Model
{
    /** @use HasFactory<WebChatSessionFactory> */
    use HasFactory;

    public const TOKEN_PREFIX = 'wvs_';

    private const TOKEN_PATTERN = '/^wvs_([0-9a-z]{26})_([A-Za-z0-9]{43})$/';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'identified' => false,
    ];

    /**
     * Every session gets its public identifier when created.
     */
    protected static function booted(): void
    {
        static::creating(function (WebChatSession $session): void {
            $session->public_id ??= strtolower((string) Str::ulid());
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
            'identified' => 'boolean',
            'profile' => 'array',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Issues a new bearer token for the session (not saved): only its hash is
     * kept, so the plain token must be handed to the visitor right away.
     */
    public function generateToken(): string
    {
        $this->public_id ??= strtolower((string) Str::ulid());

        $secret = Str::random(43);
        $this->token_hash = hash('sha256', $secret);

        return self::TOKEN_PREFIX.$this->public_id.'_'.$secret;
    }

    /**
     * Session the plain token belongs to, or null when it is malformed,
     * unknown or its secret does not match (compared in constant time).
     * Usability (expiry, revocation) is checked separately with isUsable().
     */
    public static function findByToken(string $plain): ?self
    {
        if (preg_match(self::TOKEN_PATTERN, $plain, $matches) !== 1) {
            return null;
        }

        $session = self::query()->where('public_id', $matches[1])->first();

        if ($session === null || ! hash_equals($session->token_hash, hash('sha256', $matches[2]))) {
            return null;
        }

        return $session;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Records the visitor's activity and extends the expiry by the widget's
     * idle lifetime, never beyond the absolute cap counted from creation.
     */
    public function slideExpiry(): void
    {
        $now = now();
        $idleDays = $this->connection->webChatWidget->session_idle_days ?? 30;
        $absoluteCap = ($this->created_at ?? $now)->copy()->addDays((int) config('webchat.session.absolute_days', 90));
        $idleExpiry = $now->copy()->addDays($idleDays);

        $this->forceFill([
            'last_seen_at' => $now,
            'expires_at' => $idleExpiry->lessThan($absoluteCap) ? $idleExpiry : $absoluteCap,
        ])->save();
    }

    /**
     * Keyed hash of an IP address so the raw address is never stored.
     */
    public static function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * Keyed hash of the address block an abuse limit counts together: the
     * IPv4 address itself, or the /64 network of an IPv6 address (a single
     * host usually controls a whole /64, so counting full IPv6 addresses
     * would give it billions of separate quotas).
     */
    public static function hashIpForLimits(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = (string) inet_pton($ip);
            $mappedIpv4 = str_starts_with($packed, str_repeat("\0", 10)."\xff\xff");

            $ip = $mappedIpv4
                ? (string) inet_ntop(substr($packed, 12))
                : inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
        }

        return self::hashIp($ip);
    }

    /**
     * Private broadcasting channel of the session (the client subscribes to
     * 'private-' followed by this name).
     */
    public function channelName(): string
    {
        return 'webchat.sessions.'.$this->public_id;
    }
}
