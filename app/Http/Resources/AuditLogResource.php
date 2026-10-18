<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
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
            'action' => [
                'value' => $this->action->value,
                'label' => $this->action->label(),
                'tone' => $this->action->tone(),
            ],
            'actor' => [
                'id' => $this->user_id,
                'name' => $this->actor_name ?? 'Sistema',
            ],
            'subject' => [
                'type' => $this->auditable_type,
                'id' => $this->auditable_id,
                'label' => $this->auditable_label,
            ],
            'properties' => $this->properties ?? (object) [],
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
