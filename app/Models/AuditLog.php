<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of an administrative action.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor_name
 * @property AuditAction $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $auditable_label
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'actor_name', 'action', 'auditable_type', 'auditable_id', 'auditable_label', 'properties', 'ip_address', 'user_agent', 'created_at'])]
#[WithoutTimestamps]
class AuditLog extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user that performed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The entity affected by the action.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
