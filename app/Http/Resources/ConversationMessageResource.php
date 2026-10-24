<?php

namespace App\Http\Resources;

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A customer message or an agent reply, as the inbox chat renders it.
 * Internal notes are never messages. Structured content (location, shared
 * contacts, interactive answers, reactions) is reduced to the few display
 * fields the chat needs; raw provider payloads are never exposed, and files
 * are only reachable through the authorized attachment route. updated_at,
 * and inside the same second delivery_retries (manual retries, which requeue
 * without a new attempt) and then delivery_attempts, version the delivery state:
 * the chat keeps the newest one when responses arrive out of order.
 * Messages sent through the integration API carry the token name in
 * "integration" (never its id or the text fingerprint).
 *
 * @mixin Message
 */
class ConversationMessageResource extends JsonResource
{
    /** Length of the quoted text shown above a reply. */
    private const QUOTE_LENGTH = 140;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

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
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'automatic' => ($metadata['automatic'] ?? false) === true,
            'integration' => $this->integration($metadata),
            'simulated' => ($metadata['simulated'] ?? false) === true || ($metadata['transport'] ?? null) === 'simulated',
            'template' => $this->template($metadata),
            'content' => $this->content($metadata),
            'reply_to' => $this->whenLoaded('replyTo', fn (): ?array => $this->replyTo === null ? null : [
                'id' => $this->replyTo->id,
                'direction' => $this->replyTo->direction->value,
                'type' => $this->replyTo->type->value,
                'body' => $this->replyTo->body === null ? null : Str::limit($this->replyTo->body, self::QUOTE_LENGTH),
            ]),
            'attachments' => $this->whenLoaded('attachments', fn (): array => $this->attachments
                ->map(fn (MessageAttachment $attachment): array => $this->attachment($attachment))
                ->values()
                ->all(), []),
            'sent_at' => ($this->sent_at ?? $this->created_at)?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'delivery_attempts' => $this->delivery_attempts,
            'delivery_retries' => is_int($metadata['delivery']['retries'] ?? null) ? $metadata['delivery']['retries'] : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(MessageAttachment $attachment): array
    {
        $url = route('conversations.messages.attachments.show', [
            'conversation' => $this->conversation_id,
            'message' => $this->id,
            'attachment' => $attachment->id,
        ]);
        $status = $attachment->getAttribute('status');

        return [
            'id' => $attachment->id,
            'mime_type' => $attachment->mime_type,
            'file_name' => $attachment->file_name,
            'size_bytes' => $attachment->size_bytes,
            'available' => $attachment->path !== null && $attachment->path !== '' && (! is_string($status) || $status === 'available'),
            'status' => is_string($status) ? $status : null,
            'url' => $url,
            'download_url' => $url.'?download=1',
        ];
    }

    /**
     * The integration API token that sent the message (only its name).
     *
     * @param  array<string, mixed>  $metadata
     * @return array{name: string}|null
     */
    private function integration(array $metadata): ?array
    {
        $integration = $metadata['integration'] ?? null;

        if (! is_array($integration)) {
            return null;
        }

        return ['name' => is_string($integration['name'] ?? null) && $integration['name'] !== '' ? $integration['name'] : 'Integración'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{name: string, language: string|null}|null
     */
    private function template(array $metadata): ?array
    {
        $template = $metadata['template'] ?? null;

        if (! is_array($template) || ! is_string($template['name'] ?? null)) {
            return null;
        }

        return [
            'name' => $template['name'],
            'language' => is_string($template['language'] ?? null) ? $template['language'] : null,
        ];
    }

    /**
     * Display fields of structured messages, from the stored provider content.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function content(array $metadata): ?array
    {
        $content = $metadata['content'] ?? null;

        if (! is_array($content)) {
            return null;
        }

        return match ($this->type) {
            MessageType::Location => [
                'latitude' => is_numeric($content['latitude'] ?? null) ? (float) $content['latitude'] : null,
                'longitude' => is_numeric($content['longitude'] ?? null) ? (float) $content['longitude'] : null,
                'name' => $this->text($content['name'] ?? null),
                'address' => $this->text($content['address'] ?? null),
            ],
            MessageType::Contacts => [
                'contacts' => array_map(fn (mixed $contact): array => [
                    'name' => is_array($contact) ? $this->text($contact['name']['formatted_name'] ?? null) : null,
                    'phones' => is_array($contact) && is_array($contact['phones'] ?? null)
                        ? array_values(array_filter(array_map(fn (mixed $phone): ?string => is_array($phone) ? $this->text($phone['phone'] ?? null) : null, array_slice($contact['phones'], 0, 5))))
                        : [],
                ], array_slice(array_is_list($content) ? $content : [$content], 0, 10)),
            ],
            MessageType::Interactive => [
                'kind' => $this->text($content['type'] ?? null),
                'title' => $this->text($content['button_reply']['title'] ?? $content['list_reply']['title'] ?? null),
                'description' => $this->text($content['list_reply']['description'] ?? null),
            ],
            MessageType::Reaction => [
                'emoji' => $this->text($content['emoji'] ?? null),
                'reacted_message_id' => $this->resource->relationLoaded('reactionTarget') && $this->resource->getRelation('reactionTarget') instanceof Message
                    ? $this->resource->getRelation('reactionTarget')->id
                    : null,
            ],
            default => null,
        };
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 500) : null;
    }
}
