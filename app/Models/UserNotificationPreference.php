<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How one user wants to receive one kind of notification.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property bool $in_app
 * @property bool $browser
 * @property bool $sound
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'in_app', 'browser', 'sound'])]
class UserNotificationPreference extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'browser' => 'boolean',
            'sound' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
