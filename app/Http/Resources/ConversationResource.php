<?php

namespace App\Http\Resources;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A conversation ("ticket") as listed and shown in the operational view,
 * with the operations the viewer may perform on it (resolved by the policy).
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /** Characters of the latest message shown in the list. */
    private const PREVIEW_LENGTH = 120;

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
                // Channel of the receiving connection, for the list indicator.
                'channel' => $this->channelConnection->channel->value,
                'channel_label' => $this->channelConnection->channel->label(),
                // Operational state for the composer; the server checks it again on every send.
                'state' => match (true) {
                    $this->channelConnection->isDeleted() => 'deleted',
                    ! $this->channelConnection->is_active => 'inactive',
                    default => 'active',
                },
                'can_send' => ($sendBlockReason = $this->channelConnection->sendingBlockReason()) === null,
                'send_block_reason' => $sendBlockReason,
                'credentials_verified' => $this->channelConnection->credentials_verified_at !== null,
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
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color->value,
                'is_active' => $tag->is_active,
            ])->values()->all()),
            'unread_count' => $this->when($this->resource->getAttribute('unread_count') !== null, fn (): int => (int) $this->resource->getAttribute('unread_count')),
            'last_message' => $this->whenLoaded('lastMessage', fn (): ?array => $this->lastMessagePreview()),
            // The customer wrote last and nobody answered yet.
            'awaiting_reply' => $this->status !== ConversationStatus::Closed
                && $this->last_inbound_at !== null
                && ($this->last_outbound_at === null || $this->last_inbound_at->greaterThan($this->last_outbound_at)),
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
                'tag' => $viewer->can('tag', $this->resource),
                'viewHistory' => $viewer->can('viewHistory', $this->resource),
            ],
        ];
    }

    /**
     * Short preview of the latest message for the inbox list. Only listed
     * conversations get one, and they are already visible to the viewer.
     *
     * @return array{id: int, direction: string, type: string, body: string|null, status: string, error_code: string|null, automatic: bool, sent_at: string|null}|null
     */
    private function lastMessagePreview(): ?array
    {
        $message = $this->resource->getRelation('lastMessage');

        if (! $message instanceof Message) {
            return null;
        }

        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'type' => $message->type->value,
            'body' => $message->body === null ? null : Str::limit(preg_replace('/\s+/u', ' ', $message->body) ?? '', self::PREVIEW_LENGTH),
            'status' => $message->status->value,
            // Tells a failure of unknown outcome (delivery_unknown) apart.
            'error_code' => $message->error_code,
            'automatic' => is_array($message->metadata) && ($message->metadata['automatic'] ?? false) === true,
            'sent_at' => ($message->sent_at ?? $message->created_at)?->toIso8601String(),
        ];
    }
}
