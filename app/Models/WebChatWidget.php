<?php

namespace App\Models;

use App\Enums\WebChatAvailabilityMode;
use App\Enums\WebChatIdentityMode;
use App\Enums\WebChatPosition;
use Database\Factories\WebChatWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Widget settings of a web chat connection (1:1). The public key travels in
 * the embed snippet and only identifies the widget (it is not a secret, but
 * rotating it revokes every visitor session); the identity secret signs the
 * identities the host site sends and is encrypted at rest and never
 * serialized.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $public_key
 * @property list<string> $allowed_origins Normalized origins (scheme://host[:port]) allowed to embed the widget.
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $welcome_text
 * @property string|null $offline_text
 * @property WebChatPosition $position
 * @property string $accent_color
 * @property bool $launcher_enabled
 * @property string|null $launcher_label
 * @property string|null $avatar_path
 * @property WebChatIdentityMode $identity_mode
 * @property string|null $identity_secret
 * @property Carbon|null $identity_secret_rotated_at
 * @property array{fields?: list<string>, required?: bool, privacy_notice?: string|null}|null $prechat
 * @property WebChatAvailabilityMode $availability_mode
 * @property int $session_idle_days
 * @property Carbon|null $key_rotated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'allowed_origins', 'title', 'subtitle', 'welcome_text', 'offline_text', 'position', 'accent_color',
    'launcher_enabled', 'launcher_label', 'avatar_path', 'identity_mode', 'prechat', 'availability_mode',
    'session_idle_days',
])]
#[Hidden(['identity_secret'])]
class WebChatWidget extends Model
{
    /** @use HasFactory<WebChatWidgetFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 'right',
        'accent_color' => '#2563eb',
        'launcher_enabled' => true,
        'identity_mode' => 'anonymous',
        'availability_mode' => 'always',
        'session_idle_days' => 30,
    ];

    /**
     * Every widget gets its public key and identity secret when created.
     */
    protected static function booted(): void
    {
        static::creating(function (WebChatWidget $widget): void {
            $widget->public_key ??= Str::random(48);
            $widget->identity_secret ??= Str::random(64);
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
            'allowed_origins' => 'array',
            'prechat' => 'array',
            'identity_secret' => 'encrypted',
            'identity_secret_rotated_at' => 'datetime',
            'position' => WebChatPosition::class,
            'identity_mode' => WebChatIdentityMode::class,
            'availability_mode' => WebChatAvailabilityMode::class,
            'launcher_enabled' => 'boolean',
            'session_idle_days' => 'integer',
            'key_rotated_at' => 'datetime',
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
     * Normalized origin (lowercase scheme://host, plus the port only when it
     * is not the scheme's default) or null when the value is not an http(s)
     * origin. A trailing slash is tolerated; any other path, query, fragment
     * or credentials make it invalid.
     */
    public static function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);

        if ($origin === '' || str_contains($origin, '*')) {
            return null;
        }

        $parts = parse_url($origin);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '');
    }

    /**
     * Whether the given origin is exactly one of the allowed origins (after
     * normalizing both sides): subdomains are never implied.
     */
    public function allowsOrigin(string $origin): bool
    {
        $normalized = self::normalizeOrigin($origin);

        if ($normalized === null) {
            return false;
        }

        foreach ($this->allowed_origins as $allowed) {
            if (self::normalizeOrigin($allowed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces the public key (the old snippet stops working) and revokes
     * every visitor session of the connection.
     */
    public function rotatePublicKey(): void
    {
        DB::transaction(function (): void {
            $this->forceFill([
                'public_key' => Str::random(48),
                'key_rotated_at' => now(),
            ])->save();

            $this->revokeSessions('key_rotated');
        });
    }

    /**
     * Replaces the identity secret and returns it in plain text: it is shown
     * once and never again.
     */
    public function rotateIdentitySecret(): string
    {
        $secret = Str::random(64);

        $this->forceFill([
            'identity_secret' => $secret,
            'identity_secret_rotated_at' => now(),
        ])->save();

        return $secret;
    }

    /**
     * Revokes every session still open of the connection's visitors and
     * returns how many were revoked.
     */
    public function revokeSessions(string $reason): int
    {
        return WebChatSession::query()
            ->where('connection_id', $this->connection_id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'updated_at' => now(),
            ]);
    }
}
