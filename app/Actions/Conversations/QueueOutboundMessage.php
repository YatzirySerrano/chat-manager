<?php

namespace App\Actions\Conversations;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Exceptions\ConversationConflict;
use App\Exceptions\StorageUnavailable;
use App\Jobs\DeliverOutboundMessage;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Storage\MediaDisk;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\Attachments\AttachmentKind;
use App\Support\Attachments\ChannelAttachmentPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The responsible agent writes an ordinary reply: text, or one file (image,
 * document, audio or video) with an optional caption. It is accepted only
 * while the conversation's connection can send (Connection::
 * sendingBlockReason) and the customer service window is open, stored once per client
 * reference (double submissions are harmless and never store the file
 * twice) and handed to the delivery job, which checks the window again right
 * before calling the provider. Templates cannot be sent from here. Replies
 * never extend the window.
 *
 * Files go to the private media disk (`filesystems.media_disk`) under
 * `media/{yyyy}/{mm}/{uuid}`, never to a public URL; their real content type
 * is detected from the bytes and checked against the policy of the
 * conversation's channel (ChannelAttachmentPolicy). A file the channel has
 * no native type for (ZIP on WhatsApp) is delivered as a signed expiring
 * download link (AttachmentDownloadLink), whose expiry is fixed here.
 * The copy is written and verified (MediaDisk) before the conversation is
 * locked and deleted again when the message is not recorded (refusal,
 * rollback or a repeated reference); an unready disk refuses the reply with
 * StorageUnavailable (503) and stores nothing.
 *
 * `first_response_at` is set when the first human reply is queued (not when
 * the provider accepts it); analytics compute delivery based metrics apart.
 */
class QueueOutboundMessage
{
    /** WhatsApp's limit for the caption of a media message. */
    public const CAPTION_MAX_LENGTH = 1024;

    /** UUIDv5 namespace of the client references of integration messages. */
    private const INTEGRATION_NAMESPACE = '6f1c2a4e-8b3d-5e7f-9a01-2c3d4e5f6a7b';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private CustomerServiceWindow $window,
        private ChannelAttachmentPolicy $attachments,
        private MediaDisk $mediaDisk,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?string $body, string $clientReference, ?UploadedFile $attachment = null, ?int $replyToMessageId = null): Message
    {
        $body = $body === null || trim($body) === '' ? null : $body;
        $inspected = null;
        $refusal = null;
        $stored = null;
        $duplicate = false;

        // Nothing is read or stored for someone who may not reply here (a
        // read-only viewer): the permission is checked before touching the
        // file; the full authorization runs again under the lock.
        if ($attachment !== null) {
            $this->lifecycle->ensurePermitted($actor, 'reply', $conversation);
        }

        // A repeated submission answers the stored message: its file is
        // neither inspected nor stored again.
        if ($attachment !== null && ! Message::query()->where('client_reference', $clientReference)->exists()) {
            try {
                $inspected = $this->inspect($attachment, $body, $conversation);
            } catch (ValidationException|ConversationConflict $exception) {
                // Reported inside the lock, after the reference and sending checks.
                $refusal = $exception;
            }
        }

        // The persistent copy is written (and verified) before the
        // conversation is locked, so a slow external disk never holds the
        // lock; it is removed again if the message is not recorded.
        if ($inspected !== null && $attachment !== null) {
            $stored = $this->store($attachment);
        }

        try {
            $message = DB::transaction(function () use ($conversation, $actor, $body, $clientReference, $attachment, $replyToMessageId, $inspected, $refusal, $stored, &$duplicate): Message {
                $conversation = $this->lifecycle->lock($conversation);
                $actor = $this->lifecycle->authorize($actor, 'reply', $conversation);

                $existing = Message::query()->where('client_reference', $clientReference)->first();

                if ($existing !== null) {
                    if ($existing->conversation_id !== $conversation->id) {
                        throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                    }

                    $duplicate = true;

                    return $existing;
                }

                $sendingRefusal = $this->sendingRefusal($conversation);

                if ($sendingRefusal !== null) {
                    throw new ConversationConflict($sendingRefusal['message']);
                }

                if ($refusal !== null) {
                    throw $refusal;
                }

                $type = MessageType::Text;
                $file = ['mime' => '', 'name' => '', 'size' => 0, 'sha256' => '', 'delivery' => AttachmentDeliveryMethod::Native];

                if ($attachment !== null) {
                    // Inspected before the lock, unless the reference existed then.
                    [$type, $file] = $inspected ?? $this->inspect($attachment, $body, $conversation);
                } elseif ($body === null) {
                    throw ValidationException::withMessages(['body' => 'Escribe un mensaje o adjunta un archivo.']);
                }

                if ($attachment !== null && $stored === null) {
                    // The reference existed before the lock but not anymore:
                    // a rare race; the sender simply tries again.
                    throw new StorageUnavailable('not_stored', $this->mediaDisk->name(), 'No se pudo guardar el archivo adjunto. Inténtalo de nuevo.');
                }

                $message = new Message([
                    'conversation_id' => $conversation->id,
                    'client_reference' => $clientReference,
                    'direction' => MessageDirection::Outbound,
                    'type' => $type,
                    'status' => MessageStatus::Queued,
                    'body' => $body,
                    'sender_user_id' => $actor->id,
                    'reply_to_message_id' => $this->replyTarget($conversation, $replyToMessageId),
                    'metadata' => $file['delivery'] === AttachmentDeliveryMethod::Link
                        ? [AttachmentDownloadLink::METADATA_KEY => ['expires_at' => AttachmentDownloadLink::newExpiry()->toIso8601String()]]
                        : null,
                ]);

                try {
                    $message->save();
                } catch (UniqueConstraintViolationException) {
                    // Another request stored the same reference meanwhile.
                    throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                }

                if ($stored !== null) {
                    $record = new MessageAttachment([
                        'disk' => $stored['disk'],
                        'path' => $stored['path'],
                        'mime_type' => $file['mime'],
                        'file_name' => $file['name'],
                        'size_bytes' => $file['size'],
                        'sha256' => $file['sha256'],
                        'delivery_method' => $file['delivery'],
                    ]);
                    $record->forceFill(['message_id' => $message->id])->save();
                }

                $conversation->forceFill([
                    'last_outbound_at' => now(),
                    'last_message_at' => $conversation->last_message_at === null || $conversation->last_message_at->lessThan(now()) ? now() : $conversation->last_message_at,
                    'first_response_at' => $conversation->first_response_at ?? now(),
                ])->save();

                DeliverOutboundMessage::dispatch($message->id)->afterCommit();
                MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

                return $message;
            });
        } catch (Throwable $exception) {
            // Nothing references a file whose message was rolled back.
            if ($stored !== null) {
                $this->mediaDisk->discard($stored['disk'], $stored['path']);
            }

            throw $exception;
        }

        if ($duplicate && $stored !== null) {
            // The same reference was recorded meanwhile with its own copy.
            $this->mediaDisk->discard($stored['disk'], $stored['path']);
        }

        return $message;
    }

    /**
     * A text sent by an integration API token (no human author): the same
     * conversation lock, connection and customer service window checks as an
     * agent reply, without requiring to be the assignee and without changing
     * the assignment or first_response_at. The idempotency key lives in the
     * token's own space (UUIDv5 of "{token public id}:{key}" as the client
     * reference) and the text fingerprint tells a retry from a reuse: the
     * same key and text answer the stored message (created false); another
     * text or conversation is a conflict.
     *
     * Conflicts carry their /api/v1 code as message: idempotency_key_reused,
     * conversation_closed, connection_unavailable or customer_window_closed.
     *
     * @return array{message: Message, created: bool}
     */
    public function handleForIntegration(Conversation $conversation, ApiToken $token, string $text, string $idempotencyKey): array
    {
        $clientReference = self::integrationReference($token, $idempotencyKey);
        $fingerprint = hash('sha256', $text);

        return DB::transaction(function () use ($conversation, $token, $text, $clientReference, $fingerprint): array {
            $conversation = $this->lifecycle->lock($conversation);

            // The reach was checked before the lock: a transfer committed
            // meanwhile may have taken the conversation out of it.
            if (! $token->reaches($conversation)) {
                throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->id]);
            }

            $existing = Message::query()->where('client_reference', $clientReference)->first();

            if ($existing !== null) {
                $integration = is_array($existing->metadata) ? ($existing->metadata['integration'] ?? null) : null;

                if ($existing->conversation_id !== $conversation->id
                    || ! is_array($integration)
                    || ! hash_equals((string) ($integration['fingerprint'] ?? ''), $fingerprint)) {
                    throw new ConversationConflict('idempotency_key_reused');
                }

                return ['message' => $existing, 'created' => false];
            }

            if ($conversation->status === ConversationStatus::Closed) {
                throw new ConversationConflict('conversation_closed');
            }

            $refusal = $this->sendingRefusal($conversation);

            if ($refusal !== null) {
                throw new ConversationConflict($refusal['code']);
            }

            $message = new Message([
                'conversation_id' => $conversation->id,
                'client_reference' => $clientReference,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Queued,
                'body' => $text,
                'sender_user_id' => null,
                'metadata' => ['integration' => [
                    'token_id' => $token->id,
                    'name' => $token->name,
                    'fingerprint' => $fingerprint,
                ]],
            ]);

            try {
                $message->save();
            } catch (UniqueConstraintViolationException) {
                // The same key was used meanwhile in another conversation.
                throw new ConversationConflict('idempotency_key_reused');
            }

            $conversation->forceFill([
                'last_outbound_at' => now(),
                'last_message_at' => $conversation->last_message_at === null || $conversation->last_message_at->lessThan(now()) ? now() : $conversation->last_message_at,
            ])->save();

            DeliverOutboundMessage::dispatch($message->id)->afterCommit();
            MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

            return ['message' => $message, 'created' => true];
        });
    }

    /**
     * Client reference of an integration message: the key is namespaced by
     * the token, so two tokens never collide nor learn about each other.
     */
    public static function integrationReference(ApiToken $token, string $idempotencyKey): string
    {
        return Uuid::uuid5(self::INTEGRATION_NAMESPACE, $token->public_id.':'.$idempotencyKey)->toString();
    }

    /**
     * Why a free-form message cannot be sent right now (checked again by the
     * delivery job right before calling the provider): the connection cannot
     * send, or the customer service window is closed. The code is the
     * /api/v1 conflict code; the message is the explanation for the agent.
     *
     * @return array{code: string, message: string}|null
     */
    private function sendingRefusal(Conversation $conversation): ?array
    {
        $connectionBlock = $conversation->channelConnection === null
            ? 'La conversación no tiene una conexión disponible para enviar mensajes.'
            : $conversation->channelConnection->sendingBlockReason();

        if ($connectionBlock !== null) {
            return ['code' => 'connection_unavailable', 'message' => $connectionBlock];
        }

        $window = $this->window->for($conversation);

        if (! $window->allowsFreeFormMessages()) {
            return ['code' => 'customer_window_closed', 'message' => $window->blockReason ?? 'La ventana de atención no permite enviar mensajes.'];
        }

        return null;
    }

    /**
     * Validates the real content of the file against the policy of the
     * conversation's channel, and the caption it may carry.
     *
     * @return array{0: MessageType, 1: array{mime: string, name: string, size: int, sha256: string, delivery: AttachmentDeliveryMethod}}
     */
    private function inspect(UploadedFile $attachment, ?string $caption, Conversation $conversation): array
    {
        $channel = $conversation->channelConnection?->channel;

        if ($channel === null) {
            throw new ConversationConflict('La conversación no tiene una conexión disponible para enviar mensajes.');
        }

        $rules = $this->attachments->forChannel($channel);
        $mime = $this->attachments->detectMime($attachment);
        $refusal = $rules->refusal($attachment, $mime);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['attachment' => $refusal]);
        }

        /** @var AttachmentKind $kind */
        $kind = $rules->kindFor($mime);
        $type = $kind->type;

        if ($caption !== null && ! $kind->allowsCaption) {
            throw ValidationException::withMessages(['body' => sprintf('%s no permite texto junto a %s: envía el texto en otro mensaje.', $channel->label(), $type === MessageType::Audio ? 'un audio' : 'un archivo')]);
        }

        if ($caption !== null && mb_strlen($caption) > self::CAPTION_MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'El texto que acompaña a un archivo admite hasta '.self::CAPTION_MAX_LENGTH.' caracteres.']);
        }

        $path = $attachment->getRealPath();

        return [$type, [
            'mime' => $mime,
            'name' => $this->safeName($attachment->getClientOriginalName(), $type, $attachment->getClientOriginalExtension() === '' ? $kind->canonicalExtension($mime) : null),
            'size' => (int) $attachment->getSize(),
            'sha256' => $path === false ? '' : (string) hash_file('sha256', $path),
            'delivery' => $kind->delivery,
        ]];
    }

    /**
     * The verified persistent copy on the media disk (media/{yyyy}/{mm}/{uuid}).
     *
     * @return array{disk: string, path: string}
     *
     * @throws StorageUnavailable
     */
    private function store(UploadedFile $attachment): array
    {
        $path = $attachment->getRealPath();

        if ($path === false) {
            throw new StorageUnavailable('read_failed', $this->mediaDisk->name(), 'No se pudo leer el archivo adjunto. Adjúntalo de nuevo.');
        }

        return $this->mediaDisk->putFile($path, 'media/'.now()->format('Y/m'));
    }

    /**
     * The name shown to the team and sent to the customer for documents:
     * no directories, control characters or reserved symbols; a name without
     * extension gets the one of its real content.
     */
    private function safeName(string $original, MessageType $type, ?string $extension = null): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', basename($original)) ?? '';
        $name = trim(Str::limit($name, 180, ''));
        $name = $name !== '' && $name !== '.' && $name !== '..' ? $name : $type->value;

        return $extension !== null ? rtrim($name, '.').'.'.$extension : $name;
    }

    /**
     * The quoted message, only when it belongs to the same conversation.
     */
    private function replyTarget(Conversation $conversation, ?int $messageId): ?int
    {
        if ($messageId === null) {
            return null;
        }

        $exists = Message::query()->where('conversation_id', $conversation->id)->whereKey($messageId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['reply_to_message_id' => 'El mensaje citado no pertenece a esta conversación.']);
        }

        return $messageId;
    }
}
