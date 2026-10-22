<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One record an import batch (a DataTransfer of type import) created or
 * completed. It keeps what the batch changed so the batch can be reviewed
 * and logically rolled back when it is safe (see ImportBatchRollback):
 *
 * - action "created": the batch registered the record; `applied_values`
 *   is a snapshot of it right after the import;
 * - action "filled": the batch completed empty fields of an existing
 *   record; `previous_values` holds the values it replaced (always empty
 *   ones) and `applied_values` the values it wrote.
 *
 * `audit_watermark` is the last audit entry id when the batch finished
 * with the record: any later audit entry about it is later activity.
 *
 * @property int $id
 * @property int $data_transfer_id
 * @property string $record_type Morph alias (only "contact" today).
 * @property int $record_id
 * @property int|null $line Row of the source file.
 * @property string $action
 * @property array<string, mixed>|null $previous_values
 * @property array<string, mixed>|null $applied_values
 * @property list<int>|null $added_tag_ids
 * @property list<int>|null $added_note_ids
 * @property int $audit_watermark
 * @property Carbon|null $rolled_back_at
 * @property string|null $rollback_outcome
 * @property string|null $rollback_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'data_transfer_id', 'record_type', 'record_id', 'line', 'action', 'previous_values', 'applied_values',
    'added_tag_ids', 'added_note_ids', 'audit_watermark', 'rolled_back_at', 'rollback_outcome', 'rollback_reason',
])]
class ImportBatchRecord extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_FILLED = 'filled';

    /** Everything the batch did to the record was undone. */
    public const OUTCOME_REVERTED = 'reverted';

    /** Part of it was undone; the rest changed later and was kept. */
    public const OUTCOME_PARTIAL = 'partial';

    /** Nothing was undone because the record has later activity. */
    public const OUTCOME_KEPT = 'kept';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'previous_values' => 'array',
            'applied_values' => 'array',
            'added_tag_ids' => 'array',
            'added_note_ids' => 'array',
            'audit_watermark' => 'integer',
            'rolled_back_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(DataTransfer::class, 'data_transfer_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Records of a batch that a rollback has not handled yet.
     *
     * @param  Builder<ImportBatchRecord>  $query
     */
    #[Scope]
    protected function pendingRollback(Builder $query): void
    {
        $query->whereNull('rolled_back_at');
    }
}
