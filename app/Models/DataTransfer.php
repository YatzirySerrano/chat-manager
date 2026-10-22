<?php

namespace App\Models;

use App\Enums\DataTransferStatus;
use App\Enums\DataTransferType;
use App\Enums\ExportFormat;
use Database\Factories\DataTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file exchanged asynchronously with the platform: report exports and
 * the contacts importer (type import, report "contacts-import"; the
 * uploaded source file path lives in options.source_path and `path` holds
 * the result report once processed). The file lives on a private disk and only its owner can
 * download it until it expires.
 *
 * @property int $id
 * @property DataTransferType $type
 * @property string $report
 * @property ExportFormat $format
 * @property DataTransferStatus $status
 * @property int|null $user_id
 * @property array<string, mixed>|null $filters Validated filters, never raw input.
 * @property array<string, mixed>|null $options
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $file_name
 * @property int|null $rows_total
 * @property int|null $rows_processed
 * @property int|null $rows_failed
 * @property array<string, mixed>|null $summary
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'type', 'report', 'format', 'status', 'user_id', 'filters', 'options', 'disk', 'path', 'file_name',
    'rows_total', 'rows_processed', 'rows_failed', 'summary', 'error', 'started_at', 'finished_at', 'expires_at',
])]
class DataTransfer extends Model
{
    /** @use HasFactory<DataTransferFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'export',
        'status' => 'queued',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataTransferType::class,
            'format' => ExportFormat::class,
            'status' => DataTransferStatus::class,
            'filters' => 'array',
            'options' => 'array',
            'summary' => 'array',
            'rows_total' => 'integer',
            'rows_processed' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the generated file can still be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this->status === DataTransferStatus::Completed
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Transfers owned by the user.
     *
     * @param  Builder<DataTransfer>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * Transfers whose file must be removed: completed ones past their
     * expiration, and failed or stale ones past the retention window.
     *
     * @param  Builder<DataTransfer>  $query
     */
    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->where('status', '!=', DataTransferStatus::Expired)
            ->where(fn (Builder $expired) => $expired
                ->where(fn (Builder $completed) => $completed
                    ->where('status', DataTransferStatus::Completed)
                    ->where('expires_at', '<=', now()))
                ->orWhere(fn (Builder $stale) => $stale
                    ->whereIn('status', [DataTransferStatus::Draft, DataTransferStatus::Failed, DataTransferStatus::Queued, DataTransferStatus::Processing])
                    ->where('created_at', '<=', now()->subHours(max(1, (int) config('exports.retention_hours'))))));
    }
}
