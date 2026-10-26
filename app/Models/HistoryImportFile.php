<?php

namespace App\Models;

use App\Enums\HistoryImportFileKind;
use App\Enums\HistoryImportFileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A file uploaded to a history import batch (staging). The file stays on
 * the private imports disk until the batch finishes or expires.
 *
 * @property int $id
 * @property int $data_transfer_id
 * @property HistoryImportFileKind $kind
 * @property HistoryImportFileStatus $status
 * @property string $original_name
 * @property string $extension
 * @property string $disk
 * @property string|null $path
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $format_version
 * @property list<string>|null $headers
 * @property array<string, int|null>|null $column_mapping Logical field => column index.
 * @property list<string>|null $unknown_headers
 * @property list<string>|null $missing_fields
 * @property array<string, int>|null $media_index Base name (lowercase) => uncompressed size.
 * @property array<string, mixed>|null $issues
 * @property int $rows_total
 * @property int $rows_accepted
 * @property int $rows_rejected
 * @property string|null $error
 * @property int|null $uploaded_by_user_id
 * @property Carbon|null $parsed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HistoryImportFile extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploaded',
        'rows_total' => 0,
        'rows_accepted' => 0,
        'rows_rejected' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HistoryImportFileKind::class,
            'status' => HistoryImportFileStatus::class,
            'size_bytes' => 'integer',
            'headers' => 'array',
            'column_mapping' => 'array',
            'unknown_headers' => 'array',
            'missing_fields' => 'array',
            'media_index' => 'array',
            'issues' => 'array',
            'rows_total' => 'integer',
            'rows_accepted' => 'integer',
            'rows_rejected' => 'integer',
            'parsed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataTransfer, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DataTransfer::class, 'data_transfer_id');
    }

    /**
     * @return HasMany<HistoryImportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(HistoryImportMessage::class);
    }
}
