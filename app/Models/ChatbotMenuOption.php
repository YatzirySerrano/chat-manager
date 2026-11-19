<?php

namespace App\Models;

use App\Enums\ChatbotOptionAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One numbered option of the chatbot menu, at any level (parent_id: the
 * submenu it belongs to; null for the main menu). Its action
 * (ChatbotOptionAction) says what choosing it does; reply_message is the
 * answer, or the text above the options of a submenu. A transfer without
 * reply uses the department's own welcome or away message.
 *
 * @property int $id
 * @property int $chatbot_menu_id
 * @property int|null $parent_id
 * @property ChatbotOptionAction $action
 * @property int|null $department_id
 * @property string|null $label
 * @property string|null $reply_message
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['parent_id', 'action', 'department_id', 'label', 'reply_message', 'position'])]
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
            'action' => ChatbotOptionAction::class,
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
     * @return BelongsTo<ChatbotMenuOption, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ChatbotMenuOption, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
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

        return $label !== '' ? $label : ($this->action === ChatbotOptionAction::Transfer ? (string) $this->department?->name : 'Opción');
    }
}
