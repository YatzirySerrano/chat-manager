<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class DepartmentMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->role();

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
            'is_deleted' => $this->isDeleted(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            // Set by DepartmentController (AgentPresence): one query for all members.
            'last_activity_at' => $this->resource->getAttribute('presence_last_activity'),
            'online' => (bool) $this->resource->getAttribute('presence_online'),
            'last_reply_at' => $this->resource->getAttribute('presence_last_reply'),
        ];
    }
}
