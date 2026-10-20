<?php

namespace App\Models;

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
use Illuminate\Support\Carbon;

/**
 * A channel the company is reachable through, such as a WhatsApp Business
 * phone number registered in Meta. Credentials are encrypted at rest and never
 * serialized; the health of a connection is derived from recorded evidence
 * (see ConnectionHealth), never stored as a claim.
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
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property Carbon|null $last_error_at
 * @property string|null $last_error_message
 * @property Carbon|null $last_activity_at
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
    use HasFactory;

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
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_error_at' => 'datetime',
            'last_activity_at' => 'datetime',
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

    /**
     * @param  Builder<Connection>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
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
