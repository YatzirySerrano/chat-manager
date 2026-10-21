<?php

namespace App\Http\Resources;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color->value,
            'is_active' => $this->is_active,
            'welcome_message' => $this->welcome_message,
            'distribution_mode' => $this->distribution_mode->value,
            'uses_custom_hours' => $this->uses_custom_hours,
            'business_hours' => $this->business_hours,
            'members_count' => $this->whenCounted('members'),
            'leaders_count' => $this->whenCounted('leaders'),
            'connections_count' => $this->whenCounted('connections'),
            'leaders' => $this->whenLoaded('leaders', fn () => $this->leaders->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'view' => $request->user()?->can('view', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'changeStatus' => $request->user()?->can('changeStatus', $this->resource) ?? false,
            ],
        ];
    }
}
