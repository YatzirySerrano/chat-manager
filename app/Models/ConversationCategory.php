<?php

namespace App\Models;

use App\Concerns\DeletesLogically;
use App\Services\ServiceRules;
use App\Support\AppColor;
use Database\Factories\ConversationCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A request category: what the customer is asking for. It classifies a
 * conversation and may override part of the service rules (see
 * App\Services\ServiceRules). Deleting it is logical (see DeletesLogically):
 * a deleted category cannot be newly chosen, stays on the conversations that
 * carry it as history, and its rule overrides stop applying.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property AppColor $color
 * @property bool $is_active
 * @property array<string, int|bool>|null $service_rules Flat map of dotted rule key → value; an absent key inherits.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'color'])]
class ConversationCategory extends Model
{
    /** @use HasFactory<ConversationCategoryFactory> */
    use DeletesLogically, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => AppColor::class,
            'is_active' => 'boolean',
            'service_rules' => 'array',
        ];
    }

    /**
     * The service rules memoize the overrides they resolved: any change to
     * them (or to the deletion state) invalidates that memory.
     */
    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            if ($model->wasRecentlyCreated || $model->wasChanged(['service_rules', 'is_active'])) {
                app(ServiceRules::class)->flush();
            }
        });
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'category_id');
    }

    /**
     * @param  Builder<ConversationCategory>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The active catalog for select inputs of any page, by name.
     *
     * @return list<array{id: int, name: string, color: string}>
     */
    public static function options(): array
    {
        return array_values(self::query()
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'color'])
            ->map(fn (self $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color->value,
            ])
            ->all());
    }
}
