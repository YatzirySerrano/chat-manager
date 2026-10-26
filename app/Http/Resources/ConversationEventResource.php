<?php

namespace App\Http\Resources;

use App\Models\ConversationEvent;
use App\Support\ProtectedAccounts;
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
        $protected = app(ProtectedAccounts::class);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'actor' => $protected->nameForRequest($this->actor, $request),
            'from_user' => $protected->nameForRequest($this->fromUser, $request),
            'to_user' => $protected->nameForRequest($this->toUser, $request),
            'from_department' => $this->fromDepartment?->name,
            'to_department' => $this->toDepartment?->name,
            'note' => $this->note,
            'trigger' => is_string($this->metadata['trigger'] ?? null) ? $this->metadata['trigger'] : null,
            'message_id' => is_int($this->metadata['message_id'] ?? null) ? $this->metadata['message_id'] : null,
            'reason' => is_string($this->metadata['reason'] ?? null) ? $this->metadata['reason'] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
