<?php

namespace App\Http\Resources;

use App\Actions\Users\UpdatePersonalQuickRepliesGrant;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->role();
        $personalGrant = app(UpdatePersonalQuickRepliesGrant::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role === null ? null : [
                'id' => $role->id,
                'label' => $role->label,
                'is_active' => $role->is_active,
                'is_root' => $role->is_root,
            ],
            'is_active' => $this->is_active,
            'max_open_conversations' => $this->max_open_conversations,
            'is_deleted' => $this->isDeleted(),
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deleted_at' => $this->deactivated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'departments' => $this->departments->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color->value,
                'is_active' => $department->is_active,
            ])->values()->all(),
            // Per-user grant of quick-replies.personal (on top of the role).
            'personal_quick_replies' => [
                'granted_by_role' => $personalGrant->grantedByRole($this->resource),
                'granted_directly' => $personalGrant->grantedDirectly($this->resource),
                'can_toggle' => $request->user() !== null && $personalGrant->allows($request->user(), $this->resource),
            ],
            'is_current_user' => $request->user()?->is($this->resource) ?? false,
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'assignRole' => $request->user()?->can('assignRole', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}
