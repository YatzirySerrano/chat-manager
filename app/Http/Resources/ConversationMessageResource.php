<?php

namespace App\Http\Resources;

use App\Actions\Messaging\RecordMessageReaction;
use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AttachmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Policies\HistoryImportPolicy;
use App\Services\Storage\MediaThumbnails;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
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
 * "integration" (never its id or the text fingerprint). Messages brought
 * from another system carry "imported" (source and the sender's name as
 * that system recorded it); they have no delivery state to show.
 *
 * What the sender did afterwards travels with the message itself:
 * "edited_at" (the body is the latest text), "revoked_at" (deleted for
 * everyone; the content is still sent, the chat decides how to show it) and
 * "reactions" (one per side of the chat at most). "is_historical" marks a
 * message imported from the history of a number linked by QR, and
 * "sent_from_phone" one the business wrote on the linked phone.
 *
 * @mixin Message
 */
class ConversationMessageResource extends JsonResource
{
    /** Length of the quoted text shown above a reply. */
    private const QUOTE_LENGTH = 140;

    /** A quoted message of the same conversation. */
    public const QUOTE_SAME_CONVERSATION = 'conversation';

    /** A quoted message of an earlier conversation the viewer may open. */
    public const QUOTE_PREVIOUS_CONVERSATION = 'previous_conversation';

    /** A quoted message of an earlier conversation the viewer may not see: nothing of it is shown. */
    public const QUOTE_RESTRICTED = 'restricted';

    /** A quoted message that is not stored here: only the excerpt the provider sent is known. */
    public const QUOTE_EXTERNAL = 'external';

    /**
     * What a quoted message without text is called.
     *
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'image' => 'Imagen',
        'video' => 'Video',
        'audio' => 'Audio',
        'document' => 'Documento',
        'sticker' => 'Sticker',
        'location' => 'Ubicación',
        'contacts' => 'Contacto',
        'poll' => 'Encuesta',
        'template' => 'Plantilla',
        'interactive' => 'Respuesta',
    ];

    /** Request attribute caching, per conversation id, where the viewer opens a quoted earlier conversation. */
    private const QUOTE_ACCESS_KEY = 'conversation_message_resource.quote_access';

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
                'id' => app(ProtectedAccounts::class)->idForRequest($this->sender, $request),
                'name' => app(ProtectedAccounts::class)->nameForRequest($this->sender, $request),
            ]),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'delivery_warning' => is_string($metadata['delivery_warning'] ?? null) ? $metadata['delivery_warning'] : null,
            'automatic' => ($metadata['automatic'] ?? false) === true,
            'integration' => $this->integration($metadata),
            'imported' => $this->imported($metadata),
            'simulated' => ($metadata['simulated'] ?? false) === true || ($metadata['transport'] ?? null) === 'simulated',
            'template' => $this->template($metadata),
            'content' => $this->content($metadata),
            // when(), not whenLoaded(): a loaded relation without a message still has a quote to draw.
            'reply_to' => $this->when($this->resource->relationLoaded('replyTo'), fn (): ?array => $this->replyTo === null ? $this->externalQuote($metadata) : $this->quote($this->replyTo, $request)),
            'reactions' => RecordMessageReaction::of($metadata),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_historical' => (bool) $this->is_historical,
            'sent_from_phone' => ($metadata['sent_from_phone'] ?? false) === true,
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
     * The message this one replies to. One of the same conversation is
     * shown as is (the viewer already sees the conversation). The customer
     * may also reply to a message of an earlier conversation with the same
     * contact and connection: its excerpt and a link to it are shown only
     * when the viewer may open that conversation (in the inbox, or in the
     * imported history archive); otherwise only that it quotes an earlier
     * conversation, with no text, ids or link. Rendering a quote never
     * changes any state.
     *
     * "preview" is what to show as the quoted text (the excerpt, or the
     * name of what was quoted when it has no text) and "from_me" whether
     * the business wrote the quoted message.
     *
     * @return array{id: int|null, direction: string|null, type: string|null, body: string|null, origin: string, conversation_id: int|null, url: string|null, preview: string, from_me: bool}
     */
    private function quote(Message $quoted, Request $request): array
    {
        $body = $quoted->body === null ? null : Str::limit($quoted->body, self::QUOTE_LENGTH);
        $shown = fn (string $origin, ?int $conversationId = null, ?string $url = null): array => [
            'id' => $quoted->id,
            'direction' => $quoted->direction->value,
            'type' => $quoted->type->value,
            'body' => $body,
            'origin' => $origin,
            'conversation_id' => $conversationId,
            'url' => $url,
            'preview' => $body ?? self::TYPE_LABELS[$quoted->type->value] ?? 'Mensaje',
            'from_me' => $quoted->direction === MessageDirection::Outbound,
        ];

        if ($quoted->conversation_id === $this->conversation_id) {
            return $shown(self::QUOTE_SAME_CONVERSATION);
        }

        $url = $this->previousConversationUrl($quoted, $request);

        if ($url !== null) {
            return $shown(self::QUOTE_PREVIOUS_CONVERSATION, $quoted->conversation_id, $url);
        }

        return [
            'id' => null,
            'direction' => null,
            'type' => null,
            'body' => null,
            'origin' => self::QUOTE_RESTRICTED,
            'conversation_id' => null,
            'url' => null,
            'preview' => '',
            'from_me' => false,
        ];
    }

    /**
     * A quote whose original is not stored here (it was written before the
     * number was linked, or never reached the platform): drawn from the
     * excerpt WhatsApp sent with the reply. There is nothing to jump to.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{id: null, direction: string|null, type: null, body: string, origin: string, conversation_id: null, url: null, preview: string, from_me: bool}|null
     */
    private function externalQuote(array $metadata): ?array
    {
        $quote = is_array($metadata['reply_to'] ?? null) ? $metadata['reply_to'] : [];
        $preview = is_string($quote['preview'] ?? null) ? trim($quote['preview']) : '';

        if ($preview === '') {
            return null;
        }

        $fromMe = is_bool($quote['from_me'] ?? null) ? $quote['from_me'] : null;
        $preview = Str::limit($preview, self::QUOTE_LENGTH);

        return [
            'id' => null,
            'direction' => $fromMe === null ? null : ($fromMe ? MessageDirection::Outbound->value : MessageDirection::Inbound->value),
            'type' => null,
            'body' => $preview,
            'origin' => self::QUOTE_EXTERNAL,
            'conversation_id' => null,
            'url' => null,
            'preview' => $preview,
            'from_me' => $fromMe ?? false,
        ];
    }

    /**
     * Where the viewer opens the earlier conversation of a quoted message,
     * or null when they may not see it (or it no longer exists). Resolved
     * once per conversation and request.
     */
    private function previousConversationUrl(Message $quoted, Request $request): ?string
    {
        $viewer = $request->user();

        if (! $viewer instanceof User) {
            return null;
        }

        /** @var array<int, string|null> $known */
        $known = $request->attributes->get(self::QUOTE_ACCESS_KEY, []);

        if (array_key_exists($quoted->conversation_id, $known)) {
            return $known[$quoted->conversation_id];
        }

        $conversation = $quoted->loadMissing('conversation')->conversation;
        $url = match (true) {
            $conversation === null => null,
            Gate::forUser($viewer)->allows('view', $conversation) => route('conversations.show', $conversation),
            app(HistoryImportPolicy::class)->viewArchived($viewer, $conversation)->allowed() => route('admin.history.conversations.show', $conversation),
            default => null,
        };

        $known[$quoted->conversation_id] = $url;
        $request->attributes->set(self::QUOTE_ACCESS_KEY, $known);

        return $url;
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
        $status = $status instanceof AttachmentStatus ? $status->value : $status;
        $delivery = $attachment->getAttribute('delivery_method');
        $delivery = $delivery instanceof AttachmentDeliveryMethod ? $delivery : AttachmentDeliveryMethod::Native;

        return [
            'id' => $attachment->id,
            'mime_type' => $attachment->mime_type,
            'file_name' => $attachment->file_name,
            'size_bytes' => $attachment->size_bytes,
            'available' => $attachment->path !== null && $attachment->path !== '' && (! is_string($status) || $status === 'available'),
            'status' => is_string($status) ? $status : null,
            // Why it is unavailable (historical_missing, storage_unavailable, download_failed...).
            'error_code' => $attachment->getAttribute('error_code'),
            'url' => $url,
            'download_url' => $url.'?download=1',
            // Small preview for the bubble (the original only loads when opened); null when the file is its own preview.
            'thumb_url' => app(MediaThumbnails::class)->supports($attachment->mime_type, $attachment->size_bytes) ? $url.'?thumb=1' : null,
            // Pixels of an image once measured, so its box is reserved before it loads.
            'width' => $attachment->getAttribute('width'),
            'height' => $attachment->getAttribute('height'),
            // "link": the customer received a secure download link that works until link_expires_at.
            'delivery_method' => $delivery->value,
            'link_expires_at' => $delivery === AttachmentDeliveryMethod::Link ? AttachmentDownloadLink::expiresAt($this->resource)?->toIso8601String() : null,
            'link_revoked_at' => $delivery === AttachmentDeliveryMethod::Link ? AttachmentDownloadLink::revokedAt($this->resource)?->toIso8601String() : null,
            'link_revoke_url' => $delivery === AttachmentDeliveryMethod::Link && $this->direction === MessageDirection::Outbound
                ? route('conversations.messages.attachments.revoke-link', ['conversation' => $this->conversation_id, 'message' => $this->id, 'attachment' => $attachment->id])
                : null,
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
                // Shared in real time (only the position it was shared from is known); absent otherwise.
                ...(($content['live'] ?? null) === true ? ['live' => true] : []),
            ],
            // A voice note is drawn as such; the length lets the player show it before loading.
            MessageType::Audio => [
                'voice' => ($content['voice'] ?? null) === true,
                'seconds' => is_int($content['seconds'] ?? null) ? $content['seconds'] : null,
            ],
            // "gif": a video that plays looped and muted, as WhatsApp shows a GIF.
            MessageType::Video => [
                'gif' => ($content['gif'] ?? null) === true,
                'seconds' => is_int($content['seconds'] ?? null) ? $content['seconds'] : null,
                'view_once' => ($content['view_once'] ?? null) === true,
                'thumbnail' => $this->thumbnail($content),
            ],
            MessageType::Image, MessageType::Document => [
                'view_once' => ($content['view_once'] ?? null) === true,
                'thumbnail' => $this->thumbnail($content),
            ],
            MessageType::Poll => [
                'question' => $this->text($content['question'] ?? null),
                'options' => array_values(array_filter(array_map(fn (mixed $option): ?string => $this->text($option), array_slice((array) ($content['options'] ?? []), 0, 12)))),
                'multiple' => ($content['multiple'] ?? null) === true,
            ],
            // What it was, in words ("Llamada perdida"); the body carries the same text.
            MessageType::Unsupported => [
                'kind' => $this->text($content['kind'] ?? null),
                'label' => $this->text($content['label'] ?? null),
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
            // Meta's own flag; the file is served as received, so an animated
            // WebP keeps its frames in the browser.
            MessageType::Sticker => [
                'animated' => ($content['animated'] ?? null) === true,
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

    /**
     * The small JPEG preview WhatsApp embeds in a message, as a data URI:
     * what the chat shows while the file downloads, or instead of it when
     * the file can no longer be fetched (imported history).
     *
     * @param  array<array-key, mixed>  $content
     */
    private function thumbnail(array $content): ?string
    {
        $thumbnail = $content['thumbnail'] ?? null;

        return is_string($thumbnail) && $thumbnail !== '' ? 'data:image/jpeg;base64,'.$thumbnail : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), 500) : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{source: string, sender_name: string|null, from_me: bool}|null
     */
    private function imported(array $metadata): ?array
    {
        $import = $metadata['import'] ?? null;

        if (! is_array($import)) {
            return null;
        }

        return [
            'source' => is_string($import['source'] ?? null) ? $import['source'] : 'whaticket',
            'sender_name' => is_string($import['sender_name'] ?? null) && $import['sender_name'] !== '' ? $import['sender_name'] : null,
            'from_me' => ($import['from_me'] ?? false) === true,
        ];
    }
}
