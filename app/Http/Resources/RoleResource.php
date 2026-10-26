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
            'description' => $this->presentedDescription(),
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'is_active' => $this->is_active,
            'is_deleted' => $this->isDeleted(),
            'is_root' => $this->is_root,
            'users_count' => $this->users_count ?? 0,
            // Active accounts: the ones a change of the role reaches now.
            'active_users_count' => (int) ($this->resource->getAttribute('active_users_count') ?? 0),
            // Active holders who also receive each permission from another
            // role or a direct grant (revoking it here does not remove it).
            'retained_elsewhere' => (object) ($this->resource->getAttribute('retained_elsewhere') ?? []),
            'permissions' => $this->permissionNames(),
            'is_current_user_role' => $request->user()?->role()?->is($this->resource) ?? false,
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
