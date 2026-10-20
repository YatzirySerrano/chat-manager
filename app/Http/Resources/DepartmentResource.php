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
        ];
    }
}
