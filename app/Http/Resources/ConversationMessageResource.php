<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer message or an agent reply. Internal notes are never messages.
 *
 * @mixin Message
 */
class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction->value,
            'type' => $this->type->value,
            'body' => $this->body,
            'status' => $this->status->value,
            'sender' => $this->whenLoaded('sender', fn (): ?array => $this->sender === null ? null : [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
            ]),
            'error_message' => $this->error_message,
            'sent_at' => ($this->sent_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
