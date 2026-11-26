<?php

namespace App\Models\Storage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A file of a mounted disk (the NAS) with a copy on this server
 * (App\Services\Storage\LocalMedia). "pending" files exist only here and are
 * never pruned; "synced" ones have a verified copy on the NAS and their local
 * copy is a cache; "conflict" ones found a different file at their NAS path
 * and wait for an administrator (kept here).
 *
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $path_hash
 * @property string $origin
 * @property string $state
 * @property int $size_bytes
 * @property string|null $sha256
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $claimed_until
 * @property string|null $last_error
 * @property Carbon|null $synced_at
 * @property int|null $sync_ms
 * @property bool $local_present
 * @property Carbon|null $local_purged_at
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaReplica extends Model
{
    public const PENDING = 'pending';

    public const SYNCED = 'synced';

    public const CONFLICT = 'conflict';

    /** Kept on this server only (MEDIA_SYNC_TYPES leaves it out of the NAS); never pruned. */
    public const KEPT = 'kept';

    public const ORIGIN_UPLOAD = 'upload';

    public const ORIGIN_CACHE = 'cache';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'sync_ms' => 'integer',
            'local_present' => 'boolean',
            'next_attempt_at' => 'datetime',
            'claimed_until' => 'datetime',
            'synced_at' => 'datetime',
            'local_purged_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public static function hashFor(string $disk, string $path): string
    {
        return hash('sha256', $disk.'|'.ltrim($path, '/'));
    }
}
