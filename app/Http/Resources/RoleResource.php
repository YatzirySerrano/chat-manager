<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
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
            'label' => $this->label,
            'description' => $this->description,
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'is_active' => $this->is_active,
            'is_root' => $this->is_root,
            'users_count' => $this->users_count ?? 0,
            'permissions' => $this->permissionNames(),
            'is_current_user_role' => $request->user()?->role()?->is($this->resource) ?? false,
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'changeStatus' => $request->user()?->can('changeStatus', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
