<?php

namespace App\Models\Ai;

use App\Models\Connection;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether the assistant answers on one connection (never on a Meta one:
 * AiAssistant::supports()), the department it hands the conversation to
 * (null: it stays where it is) and whether it answers only outside hours.
 *
 * @property int $id
 * @property int $connection_id
 * @property bool $is_enabled
 * @property int|null $handoff_department_id
 * @property bool $only_outside_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiConnectionSetting extends Model
{
    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => false,
        'only_outside_hours' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'only_outside_hours' => 'boolean',
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
     * @return BelongsTo<Department, $this>
     */
    public function handoffDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'handoff_department_id');
    }
}
