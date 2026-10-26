<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as the integration API exposes it: attention state and who
 * attends it, never the contact's phone, email or the internal notes. It
 * expects channelConnection, department, contact and assignee loaded.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'connection' => $this->channelConnection === null ? null : [
                'id' => $this->channelConnection->id,
                'name' => $this->channelConnection->name,
                'channel' => $this->channelConnection->channel->value,
            ],
            'department' => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ],
            'contact' => [
                'id' => $this->contact_id,
                'display_name' => $this->contact?->name,
                // False while the customer is a provisional contact, not saved into the directory.
                'is_saved' => $this->contact?->isSaved() ?? false,
            ],
            // A protected account (root role) is presented as the system
            // administration, without its id (an integration token is never
            // the root authority).
            'assigned_agent' => $this->assignee === null ? null : (app(ProtectedAccounts::class)->hides($this->assignee, null)
                ? ['id' => null, 'name' => ProtectedAccounts::SYSTEM_NAME]
                : ['id' => $this->assignee->id, 'name' => $this->assignee->name]),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
