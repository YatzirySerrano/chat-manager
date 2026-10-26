<?php

namespace App\Models;

use App\Enums\HistoryImportDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bulk classification rule of a history import batch: an inventory
 * filter and the destination it gives to the conversations it matches.
 *
 * @property int $id
 * @property int $data_transfer_id
 * @property string $name
 * @property array<string, mixed> $criteria
 * @property HistoryImportDecision $decision
 * @property bool $overwrite
 * @property int|null $created_by_user_id
 * @property int $matched_count
 * @property int $applied_count
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HistoryImportRule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'decision' => HistoryImportDecision::class,
            'overwrite' => 'boolean',
            'matched_count' => 'integer',
            'applied_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
