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
 * @property string|null $background_color Background of the conversation (#rrggbb); null keeps the theme's own.
 * @property string|null $visitor_bubble_color Bubbles of the visitor's messages; null uses the accent color.
 * @property string|null $visitor_text_color Text on those bubbles; null picks a readable one.
 * @property string|null $agent_bubble_color Bubbles of the agent's messages (and the welcome text); null keeps the theme's own.
 * @property string|null $agent_text_color Text on those bubbles; null picks a readable one.
 * @property bool $launcher_enabled
 * @property string|null $launcher_label
 * @property string|null $launcher_color Background of the floating button; null uses the accent color.
 * @property string|null $launcher_icon_color Icon and text of the floating button; null picks a readable one.
 * @property string $launcher_shape One of self::LAUNCHER_SHAPES; the text only shows as a pill.
 * @property string $launcher_icon One of self::LAUNCHER_ICONS.
 * @property string|null $launcher_icon_path Image of the button uploaded by the administrator (replaces the icon); stored like avatar_path.
 * @property string $launcher_size One of self::LAUNCHER_SIZES.
 * @property int $launcher_offset_x Extra distance from the side edge, in px (0 to self::MAX_LAUNCHER_OFFSET).
 * @property int $launcher_offset_y Extra distance from the bottom edge, in px.
 * @property bool $launcher_pulse Whether the button pulses to draw attention (until opened, or while unread).
 * @property int|null $launcher_width Fixed width of the button in px (self::LAUNCHER_WIDTH); null fits its content.
 * @property string $launcher_font_size One of self::LAUNCHER_SIZES (size of the button's text).
 * @property int $panel_width Width of the chat window on wide screens, px (self::PANEL_WIDTH).
 * @property int $panel_height Height of the chat window on wide screens, px (self::PANEL_HEIGHT).
 * @property string|null $online_text Status line while the team is available; null = «En línea».
 * @property string|null $offline_status_text Status line while it is not; null = «Fuera de línea».
 * @property string|null $composer_placeholder Placeholder of the message box; null = «Escribe tu mensaje…».
 * @property bool $allow_visitor_files Whether visitors may send files from the widget.
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
    'session_idle_days', 'background_color', 'visitor_bubble_color', 'visitor_text_color', 'agent_bubble_color',
    'agent_text_color', 'allow_visitor_files', 'launcher_color', 'launcher_icon_color', 'launcher_shape',
    'launcher_icon', 'launcher_size', 'launcher_offset_x', 'launcher_offset_y', 'launcher_pulse',
    'launcher_width', 'launcher_font_size', 'panel_width', 'panel_height', 'online_text', 'offline_status_text',
    'composer_placeholder',
])]
#[Hidden(['identity_secret'])]
class WebChatWidget extends Model
{
    /** @use HasFactory<WebChatWidgetFactory> */
    use HasFactory;

    /**
     * Optional colors of the conversation (#rrggbb or null = the widget's own look).
     *
     * @var list<string>
     */
    public const COLORS = [
        'background_color', 'visitor_bubble_color', 'visitor_text_color', 'agent_bubble_color', 'agent_text_color',
        'launcher_color', 'launcher_icon_color',
    ];

    /** @var list<string> */
    public const LAUNCHER_SHAPES = ['circle', 'rounded', 'pill'];

    /** @var list<string> */
    public const LAUNCHER_ICONS = ['chat', 'message', 'headset', 'help'];

    /** @var list<string> */
    public const LAUNCHER_SIZES = ['small', 'medium', 'large'];

    /** Largest extra distance of the floating button from the edges, in px. */
    public const MAX_LAUNCHER_OFFSET = 120;

    /** Bounds of a fixed button width, in px. */
    public const LAUNCHER_WIDTH = ['min' => 48, 'max' => 260];

    /** Bounds of the chat window on wide screens, in px (the default is the original 380 x 600). */
    public const PANEL_WIDTH = ['min' => 300, 'max' => 480, 'default' => 380];

    public const PANEL_HEIGHT = ['min' => 420, 'max' => 720, 'default' => 600];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 'right',
        'accent_color' => '#2563eb',
        'launcher_enabled' => true,
        'allow_visitor_files' => true,
        'launcher_shape' => 'circle',
        'launcher_icon' => 'chat',
        'launcher_size' => 'medium',
        'launcher_offset_x' => 0,
        'launcher_offset_y' => 0,
        'launcher_pulse' => false,
        'launcher_font_size' => 'medium',
        'panel_width' => 380,
        'panel_height' => 600,
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
            'allow_visitor_files' => 'boolean',
            'launcher_offset_x' => 'integer',
            'launcher_offset_y' => 'integer',
            'launcher_pulse' => 'boolean',
            'launcher_width' => 'integer',
            'panel_width' => 'integer',
            'panel_height' => 'integer',
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
     * The optional colors of the conversation and of the button, keyed by attribute.
     *
     * @return array<string, string|null>
     */
    public function colors(): array
    {
        $colors = [];

        foreach (self::COLORS as $attribute) {
            $value = $this->getAttribute($attribute);
            $colors[$attribute] = is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : null;
        }

        return $colors;
    }

    /**
     * How the floating button is drawn on the host page (the loader).
     *
     * @return array{launcher_color: string|null, launcher_icon_color: string|null, launcher_shape: string, launcher_icon: string, launcher_size: string, launcher_offset: array{x: int, y: int}, launcher_pulse: bool, launcher_width: int|null, launcher_font_size: string, panel: array{width: int, height: int}}
     */
    public function launcherStyle(): array
    {
        $colors = $this->colors();
        $offset = fn (mixed $value): int => max(0, min(self::MAX_LAUNCHER_OFFSET, (int) $value));

        return [
            'launcher_color' => $colors['launcher_color'],
            'launcher_icon_color' => $colors['launcher_icon_color'],
            'launcher_shape' => in_array($this->launcher_shape, self::LAUNCHER_SHAPES, true) ? $this->launcher_shape : 'circle',
            'launcher_icon' => in_array($this->launcher_icon, self::LAUNCHER_ICONS, true) ? $this->launcher_icon : 'chat',
            'launcher_size' => in_array($this->launcher_size, self::LAUNCHER_SIZES, true) ? $this->launcher_size : 'medium',
            'launcher_offset' => ['x' => $offset($this->launcher_offset_x), 'y' => $offset($this->launcher_offset_y)],
            'launcher_pulse' => $this->launcher_pulse,
            'launcher_width' => $this->launcher_width !== null
                ? max(self::LAUNCHER_WIDTH['min'], min(self::LAUNCHER_WIDTH['max'], $this->launcher_width))
                : null,
            'launcher_font_size' => in_array($this->launcher_font_size, self::LAUNCHER_SIZES, true) ? $this->launcher_font_size : 'medium',
            'panel' => [
                'width' => max(self::PANEL_WIDTH['min'], min(self::PANEL_WIDTH['max'], $this->panel_width ?: self::PANEL_WIDTH['default'])),
                'height' => max(self::PANEL_HEIGHT['min'], min(self::PANEL_HEIGHT['max'], $this->panel_height ?: self::PANEL_HEIGHT['default'])),
            ],
        ];
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
