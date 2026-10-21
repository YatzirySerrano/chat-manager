<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation ("ticket") as listed and shown in the operational view,
 * with the operations the viewer may perform on it (resolved by the policy).
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
        /** @var User|null $viewer */
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'version' => $this->version,
            'contact' => $this->whenLoaded('contact', fn (): array => [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'profile_name' => $this->contact->profile_name,
                'phone_e164' => $this->contact->phone_e164,
            ]),
            'connection' => $this->whenLoaded('channelConnection', fn (): array => [
                'id' => $this->channelConnection->id,
                'name' => $this->channelConnection->name,
                'phone' => $this->channelConnection->display_phone_number,
            ]),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department === null ? null : [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'color' => $this->department->color->value,
                'is_active' => $this->department->is_active,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'is_active' => $this->assignee->is_active,
            ]),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'on_hold_at' => $this->on_hold_at?->toIso8601String(),
            'on_hold_reason' => $this->on_hold_reason,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopen_count' => $this->reopen_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'messages_count' => $this->whenCounted('messages'),
            'can' => $viewer === null ? [] : [
                'view' => $viewer->can('view', $this->resource),
                'accept' => $viewer->can('accept', $this->resource),
                'assign' => $viewer->can('assign', $this->resource),
                'transfer' => $viewer->can('transfer', $this->resource),
                'release' => $viewer->can('release', $this->resource),
                'hold' => $viewer->can('hold', $this->resource),
                'resume' => $viewer->can('resume', $this->resource),
                'close' => $viewer->can('close', $this->resource),
                'reopen' => $viewer->can('reopen', $this->resource),
                'addNote' => $viewer->can('addNote', $this->resource),
                'reply' => $viewer->can('reply', $this->resource),
                'viewHistory' => $viewer->can('viewHistory', $this->resource),
            ],
        ];
    }
}
