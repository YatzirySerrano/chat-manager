<?php

namespace App\Http\Resources;

use App\Models\ConversationCategory;
use App\Services\ServiceRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationCategory
 */
class ConversationCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rules = app(ServiceRules::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color->value,
            'is_active' => $this->is_active,
            'is_deleted' => $this->isDeleted(),
            'conversations_count' => $this->whenCounted('conversations'),
            // Rules of the category for its editor (no query per row) and how many it overrides.
            'rules' => $rules->describe(null, $this->resource),
            'overrides_count' => count($rules->overridesOf(ServiceRules::LEVEL_CATEGORY, $this->service_rules)),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
                // Irreversible (permanent-deletion.*, Superadministrador): only for records already deleted.
                'purge' => $request->user()?->can('deletePermanently', $this->resource) ?? false,
            ],
        ];
    }
}
