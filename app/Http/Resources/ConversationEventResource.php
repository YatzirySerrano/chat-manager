<?php

namespace App\Http\Resources;

use App\Models\ConversationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationEvent
 */
class ConversationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'actor' => $this->actor?->name,
            'from_user' => $this->fromUser?->name,
            'to_user' => $this->toUser?->name,
            'from_department' => $this->fromDepartment?->name,
            'to_department' => $this->toDepartment?->name,
            'note' => $this->note,
            'trigger' => is_string($this->metadata['trigger'] ?? null) ? $this->metadata['trigger'] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
