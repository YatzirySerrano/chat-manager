<?php

namespace App\Http\Resources\WebChat;

use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\WebChatSession;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\WebChat\VisitorAgentAvatars;
use App\Services\WebChat\VisitorCursor;
use App\Services\WebChat\VisitorSessions;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A message of the visitor's conversation as the widget shows it. Only what
 * the visitor may see: the text, who wrote it (the agent's name and picture
 * through a signed URL bound to the session, never an id; whether it is an
 * automatic message of the virtual assistant) and the files of the message (the ones the agent sent and the ones
 * the visitor uploaded), through short-lived signed URLs bound to the
 * session. Never notes, events, metadata, error codes or user ids.
 * Expects 'sender' (with its avatar columns) and 'attachments' loaded.
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
        $session = $session instanceof WebChatSession ? $session : null;

        // The agent a message speaks for: who wrote it, or the agent an
        // automatic greeting introduces («Hola, soy Ana», «Te comunico con…»).
        $agent = $inbound ? null : ($this->sender_user_id !== null ? $this->sender : $this->introducedAgent());

        return [
            'cursor' => app(VisitorCursor::class)->encode($this->id),
            'direction' => $this->direction->value,
            'author' => $inbound ? 'visitor' : ($agent !== null ? 'agent' : 'system'),
            'agent_name' => $agent !== null ? app(ProtectedAccounts::class)->nameForRequest($agent, $request) : null,
            'agent_avatar_url' => $agent !== null ? app(VisitorAgentAvatars::class)->url($agent, $session) : null,
            // Welcome, chatbot and AI messages: «Asistente virtual» in the widget.
            'assistant' => ! $inbound && $agent === null && ($this->metadata['automatic'] ?? false) === true,
            'body' => $this->body,
            'attachments' => $session === null || ($inbound && ! str_starts_with((string) $this->whatsapp_message_id, VisitorSessions::WIDGET_PREFIX)) ? [] : $this->attachments
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

    /**
     * The agent an automatic action message introduces (agent assigned or
     * transferred to another agent), from its trace; null otherwise.
     */
    private function introducedAgent(): ?User
    {
        $kind = $this->metadata['automatic_kind'] ?? null;
        $agentId = $this->metadata['agent_user_id'] ?? null;

        if (! in_array($kind, [AutomaticMessageKind::AgentAssigned->value, AutomaticMessageKind::AgentTransfer->value], true) || ! is_int($agentId)) {
            return null;
        }

        return User::query()->find($agentId);
    }
}
