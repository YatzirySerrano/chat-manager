<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer message or a reply as the integration API exposes it. The
 * provider's raw error message, the metadata and the author's identity are
 * never included: only who wrote it (contact, agent, system or integration)
 * and the stable error code. It expects attachments loaded.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction->value,
            'type' => $this->type->value,
            'body' => $this->body,
            'status' => $this->status->value,
            'status_label' => $this->status === MessageStatus::Failed && $this->error_code === 'delivery_unknown'
                ? 'Entrega no confirmada'
                : $this->status->label(),
            'author' => $this->author(),
            'error_code' => $this->error_code,
            'attachments' => $this->attachments
                ->map(fn (MessageAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function author(): string
    {
        if ($this->direction === MessageDirection::Inbound) {
            return 'contact';
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return match (true) {
            is_array($metadata['integration'] ?? null) => 'integration',
            $this->sender_user_id !== null => 'agent',
            default => 'system',
        };
    }
}
