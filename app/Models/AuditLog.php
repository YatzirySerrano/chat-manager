<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\DataScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only record of an administrative action: once written it can be
 * neither changed nor deleted through the model.
 *
 * `restricted` entries (about the root authority, see AuditLogger) are only
 * readable by the root authority; every query that shows entries goes
 * through visibleTo().
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
 * @property bool $restricted
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'actor_name', 'action', 'auditable_type', 'auditable_id', 'auditable_label', 'properties', 'ip_address', 'user_agent', 'restricted', 'created_at'])]
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
            'restricted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit entries are append-only and cannot be modified.'));
        static::deleting(fn (): never => throw new LogicException('Audit entries are append-only and cannot be deleted.'));
    }

    /**
     * Entries the viewer may read: everything for the root authority;
     * otherwise never restricted entries, and only the viewer's own entries
     * unless the viewer has the organization-wide scope.
     *
     * @param  Builder<AuditLog>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->hasRootAuthority()) {
            return;
        }

        $query->where('restricted', false)
            ->when($viewer->dataScope() !== DataScope::Global, fn (Builder $own) => $own->where('user_id', $viewer->id));
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
