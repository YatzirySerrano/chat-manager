<?php

namespace App\Http\Resources;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\QuickReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuickReply
 */
class QuickReplyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $reachableDepartmentIds = $actor === null || $actor->dataScope() === DataScope::Global ? null : $actor->departmentIds();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'shortcut' => $this->shortcut,
            'body' => $this->body,
            'is_active' => $this->is_active,
            'is_deleted' => $this->isDeleted(),
            'is_global' => $this->is_global,
            'is_personal' => $this->isPersonal(),
            'is_own' => $this->isPersonal() && $actor !== null && $this->owner_user_id === $actor->id,
            // Only loaded for the supervision segment (other users' replies).
            'owner' => $this->whenLoaded('owner', fn () => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'departments' => $this->isPersonal() ? [] : $this->whenLoaded('departments', fn () => $this->departments
                // Departments the viewer does not belong to are not named.
                ->filter(fn (Department $department): bool => $reachableDepartmentIds === null || in_array($department->id, $reachableDepartmentIds, true))
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'color' => $department->color->value,
                    'is_active' => $department->is_active,
                ])->values()->all()),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }
}
