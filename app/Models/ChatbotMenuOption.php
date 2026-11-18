<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One numbered option of a welcome menu: the department it sends the
 * conversation to, the text shown in the menu (the department's name when
 * empty) and the reply sent once chosen (the department's own welcome or
 * away message when empty).
 *
 * @property int $id
 * @property int $chatbot_menu_id
 * @property int $department_id
 * @property string|null $label
 * @property string|null $reply_message
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['department_id', 'label', 'reply_message', 'position'])]
class ChatbotMenuOption extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChatbotMenu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(ChatbotMenu::class, 'chatbot_menu_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * What the menu shows for the option.
     */
    public function displayLabel(): string
    {
        $label = trim((string) $this->label);

        return $label !== '' ? $label : (string) $this->department?->name;
    }
}
