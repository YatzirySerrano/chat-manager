<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionHealth;
use App\Enums\ConnectionProvider;
use App\Enums\DataScope;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A channel the company is reachable through, such as a WhatsApp Business
 * phone number registered in Meta or a web chat widget (webChatWidget).
 * Credentials are encrypted at rest and never serialized; the health of a connection is derived from recorded evidence
 * (see ConnectionHealth), never stored as a claim.
 *
 * Two independent states: `is_active` is the operational activation with
 * Meta (Activar/Desactivar) and `deleted_at` the logical deletion
 * (Eliminar/Restaurar). A deleted connection is always inactive, keeps its
 * conversations and history and keeps its Phone number ID reserved. Only an
 * operational connection (see isOperational) may send; an inactive (not
 * deleted) one still receives and stores inbound messages (ADR-META-5).
 *
 * @property int $id
 * @property string $name
 * @property ConnectionChannel $channel
 * @property ConnectionProvider $provider
 * @property string|null $display_phone_number
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string|null $verified_name
 * @property string|null $meta_app_id
 * @property string|null $access_token
 * @property string|null $app_secret
 * @property string|null $webhook_verify_token
 * @property string|null $webhook_key Random opaque identifier of the webhook URL (not a secret; events are signed).
 * @property bool $is_active
 * @property int|null $default_department_id
 * @property string|null $welcome_message
 * @property bool $welcome_enabled
 * @property string|null $away_message
 * @property bool $away_enabled
 * @property string|null $farewell_message
 * @property bool $farewell_enabled
 * @property int|null $reopen_window_minutes Closed conversations are reopened when the customer writes within this window; null always starts a new one.
 * @property Carbon|null $credentials_verified_at
 * @property string|null $credentials_error
 * @property string|null $phone_status Phone number `status` reported by Meta (e.g. CONNECTED).
 * @property string|null $code_verification_status
 * @property string|null $platform_type
 * @property string|null $quality_rating
 * @property Carbon|null $webhook_verified_at
 * @property Carbon|null $last_webhook_at
 * @property bool|null $app_subscribed Result of the last subscribed_apps check (null: never checked).
 * @property Carbon|null $subscription_checked_at
 * @property array<string, mixed>|null $provider_signals Last account/quality notices reported by Meta.
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property Carbon|null $last_error_at
 * @property string|null $last_error_message
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $deleted_at Logical deletion; explicit, never a global scope (history relations must keep resolving it).
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'channel', 'provider', 'display_phone_number', 'phone_number_id', 'business_account_id', 'verified_name',
    'meta_app_id', 'access_token', 'app_secret', 'webhook_verify_token', 'default_department_id',
    'welcome_message', 'welcome_enabled', 'away_message', 'away_enabled', 'farewell_message', 'farewell_enabled',
    'reopen_window_minutes',
])]
#[Hidden(['access_token', 'app_secret', 'webhook_verify_token'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => 'whatsapp',
        'provider' => 'meta_cloud_api',
        'is_active' => false,
        'welcome_enabled' => false,
        'away_enabled' => false,
        'farewell_enabled' => false,
    ];

    /**
     * Every connection gets its own webhook URL identifier when created.
     */
    protected static function booted(): void
    {
        static::creating(function (Connection $connection): void {
            $connection->webhook_key ??= Str::random(48);
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
            'channel' => ConnectionChannel::class,
            'provider' => ConnectionProvider::class,
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'is_active' => 'boolean',
            'welcome_enabled' => 'boolean',
            'away_enabled' => 'boolean',
            'farewell_enabled' => 'boolean',
            'reopen_window_minutes' => 'integer',
            'credentials_verified_at' => 'datetime',
            'webhook_verified_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'app_subscribed' => 'boolean',
            'subscription_checked_at' => 'datetime',
            'provider_signals' => 'array',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Departments authorized to receive the conversations of the connection.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    /**
     * Department that receives new conversations when no rule applies.
     *
     * @return BelongsTo<Department, $this>
     */
    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'default_department_id');
    }

    /**
     * Contacts that have interacted through the connection.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot(['first_interaction_at', 'last_interaction_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<MessageTemplate, $this>
     */
    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    /**
     * @return HasMany<WebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    /**
     * Widget settings of a web chat connection (none for WhatsApp).
     *
     * @return HasOne<WebChatWidget, $this>
     */
    public function webChatWidget(): HasOne
    {
        return $this->hasOne(WebChatWidget::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Current health including the inactive overlay.
     */
    public function health(): ConnectionHealth
    {
        return ConnectionHealth::resolve($this);
    }

    /**
     * Health of the configuration and integration regardless of `is_active`.
     */
    public function configurationHealth(): ConnectionHealth
    {
        return ConnectionHealth::resolveConfiguration($this);
    }

    /**
     * Whether every identifier and the token needed to call the provider are saved.
     */
    public function hasRequiredCredentials(): bool
    {
        return filled($this->phone_number_id) && filled($this->business_account_id) && filled($this->access_token);
    }

    public function isWebChat(): bool
    {
        return $this->channel === ConnectionChannel::WebChat;
    }

    /**
     * Whether the connection has what its channel needs to operate: the Meta
     * credentials for WhatsApp, or a widget with at least one authorized
     * origin for the web chat. hasRequiredCredentials() keeps meaning "Meta
     * credentials" so nothing calls Meta for a web chat connection.
     */
    public function isConfigured(): bool
    {
        return match ($this->channel) {
            ConnectionChannel::Whatsapp => $this->hasRequiredCredentials(),
            ConnectionChannel::WebChat => ($widget = $this->loadMissing('webChatWidget')->webChatWidget) !== null && $widget->allowed_origins !== [],
        };
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Whether the connection may exchange traffic with the provider: active
     * and not deleted. Inbound processing and outbound delivery must check it.
     */
    public function isOperational(): bool
    {
        return $this->is_active && ! $this->isDeleted();
    }

    /**
     * Why a reply through this connection is known to fail right now, in
     * Spanish for the agent; null when it may be sent. It covers what is
     * certain before calling the provider: a deleted or deactivated
     * connection and, for WhatsApp, missing credentials and credentials Meta
     * rejected in the last verification (unverified credentials do not block:
     * Meta decides). A web chat connection only needs its widget configured.
     */
    public function sendingBlockReason(): ?string
    {
        $channel = $this->channel->label();

        return match (true) {
            $this->isDeleted() => "La conexión de {$channel} «{$this->name}» fue eliminada: ya no se pueden enviar mensajes desde esta conversación. El historial sigue disponible.",
            ! $this->is_active => "La conexión de {$channel} «{$this->name}» está desactivada: no se pueden enviar mensajes hasta que un administrador la vuelva a activar.",
            $this->isWebChat() && ! $this->isConfigured() => "La conexión de {$channel} «{$this->name}» no tiene configurado su widget ni sus dominios autorizados: un administrador debe completarlos antes de enviar mensajes.",
            $this->isWebChat() => null,
            ! $this->hasRequiredCredentials() => "La conexión de {$channel} «{$this->name}» no tiene guardados sus identificadores o su token de acceso de Meta: un administrador debe completarlos antes de enviar mensajes.",
            filled($this->credentials_error) => "Meta rechazó las credenciales de la conexión de {$channel} «{$this->name}»: un administrador debe corregirlas y verificarlas antes de enviar mensajes.",
            default => null,
        };
    }

    /**
     * @param  Builder<Connection>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function constrainDeletion(Builder $query, bool $deleted): void
    {
        $deleted
            ? $query->whereNotNull($query->qualifyColumn('deleted_at'))
            : $query->whereNull($query->qualifyColumn('deleted_at'));
    }

    /**
     * Global users see every connection; others only those authorized for one
     * of their departments.
     *
     * @param  Builder<Connection>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->dataScope() !== DataScope::Global) {
            $query->whereHas('departments', fn (Builder $departments) => $departments->whereKey($user->departmentIds()));
        }
    }
}
