<?php

namespace App\Http\Resources\WebChat;

use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WebChatSession;
use App\Services\WebChat\VisitorCursor;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A message of the visitor's conversation as the widget shows it. Only what
 * the visitor may see: the text, who wrote it (the agent's name, never an
 * id) and the files the agent sent, through short-lived signed URLs bound to
 * the session. Never notes, events, metadata, error codes or user ids.
 * Expects 'sender' and 'attachments' loaded.
 *
 * @mixin Message
 */
class VisitorMessageResource extends JsonResource
{
    /**
     * Outbound statuses the visitor sees (the message really left).
     *
     * @var list<MessageStatus>
     */
    public const VISIBLE_OUTBOUND = [MessageStatus::Accepted, MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inbound = $this->direction === MessageDirection::Inbound;
        $session = $request->attributes->get('webchat_session');

        return [
            'cursor' => app(VisitorCursor::class)->encode($this->id),
            'direction' => $this->direction->value,
            'author' => $inbound ? 'visitor' : ($this->sender_user_id !== null ? 'agent' : 'system'),
            'agent_name' => ! $inbound && $this->sender_user_id !== null ? app(ProtectedAccounts::class)->nameForRequest($this->sender, $request) : null,
            'body' => $this->body,
            'attachments' => $inbound || ! $session instanceof WebChatSession ? [] : $this->attachments
                ->filter(fn (MessageAttachment $attachment): bool => $attachment->status === AttachmentStatus::Available && filled($attachment->path))
                ->map(fn (MessageAttachment $attachment): array => [
                    'name' => $attachment->file_name ?? 'archivo',
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'url' => URL::temporarySignedRoute(
                        'api.v1.widget.session.attachments.show',
                        now()->addSeconds(max(1, (int) config('webchat.attachments.url_ttl_seconds', 600))),
                        ['attachment' => $attachment->id, 's' => $session->public_id],
                    ),
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
