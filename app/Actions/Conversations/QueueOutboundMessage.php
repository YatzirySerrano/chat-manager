<?php

namespace App\Actions\Conversations;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Exceptions\ConversationConflict;
use App\Jobs\DeliverOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Conversations\OutboundAttachmentRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
 * is detected from the bytes.
 *
 * `first_response_at` is set when the first human reply is queued (not when
 * the provider accepts it); analytics compute delivery based metrics apart.
 */
class QueueOutboundMessage
{
    /** WhatsApp's limit for the caption of a media message. */
    public const CAPTION_MAX_LENGTH = 1024;

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private CustomerServiceWindow $window,
        private OutboundAttachmentRules $attachments,
    ) {}

    public function handle(Conversation $conversation, User $actor, ?string $body, string $clientReference, ?UploadedFile $attachment = null, ?int $replyToMessageId = null): Message
    {
        $body = $body === null || trim($body) === '' ? null : $body;
        $stored = null;

        try {
            return DB::transaction(function () use ($conversation, $actor, $body, $clientReference, $attachment, $replyToMessageId, &$stored): Message {
                $conversation = $this->lifecycle->lock($conversation);
                $actor = $this->lifecycle->authorize($actor, 'reply', $conversation);

                $existing = Message::query()->where('client_reference', $clientReference)->first();

                if ($existing !== null) {
                    if ($existing->conversation_id !== $conversation->id) {
                        throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                    }

                    return $existing;
                }

                // Checked again by the delivery job right before calling Meta.
                $connectionBlock = $conversation->channelConnection === null
                    ? 'La conversación no tiene una conexión de WhatsApp disponible para enviar mensajes.'
                    : $conversation->channelConnection->sendingBlockReason();

                if ($connectionBlock !== null) {
                    throw new ConversationConflict($connectionBlock);
                }

                $window = $this->window->for($conversation);

                if (! $window->allowsFreeFormMessages()) {
                    throw new ConversationConflict($window->blockReason ?? 'La ventana de atención no permite enviar mensajes.');
                }

                $type = MessageType::Text;
                $file = ['mime' => '', 'name' => '', 'size' => 0, 'sha256' => ''];

                if ($attachment !== null) {
                    [$type, $file] = $this->inspect($attachment, $body);
                } elseif ($body === null) {
                    throw ValidationException::withMessages(['body' => 'Escribe un mensaje o adjunta un archivo.']);
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
                ]);

                try {
                    $message->save();
                } catch (UniqueConstraintViolationException) {
                    // Another request stored the same reference meanwhile.
                    throw new ConversationConflict('La referencia del mensaje ya se usó en otra conversación.');
                }

                if ($attachment !== null) {
                    $stored = $this->store($attachment);

                    $record = new MessageAttachment([
                        'disk' => $stored['disk'],
                        'path' => $stored['path'],
                        'mime_type' => $file['mime'],
                        'file_name' => $file['name'],
                        'size_bytes' => $file['size'],
                        'sha256' => $file['sha256'],
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
                Storage::disk($stored['disk'])->delete($stored['path']);
            }

            throw $exception;
        }
    }

    /**
     * Validates the real content of the file and the caption it may carry.
     *
     * @return array{0: MessageType, 1: array{mime: string, name: string, size: int, sha256: string}}
     */
    private function inspect(UploadedFile $attachment, ?string $caption): array
    {
        $refusal = $this->attachments->refusal($attachment);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['attachment' => $refusal]);
        }

        $mime = $this->attachments->detectMime($attachment);
        $type = $this->attachments->typeFor($mime) ?? MessageType::Document;

        if ($caption !== null && $type === MessageType::Audio) {
            throw ValidationException::withMessages(['body' => 'WhatsApp no permite texto junto a un audio: envía el texto en otro mensaje.']);
        }

        if ($caption !== null && mb_strlen($caption) > self::CAPTION_MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'El texto que acompaña a un archivo admite hasta '.self::CAPTION_MAX_LENGTH.' caracteres.']);
        }

        $path = $attachment->getRealPath();

        return [$type, [
            'mime' => $mime,
            'name' => $this->safeName($attachment->getClientOriginalName(), $type),
            'size' => (int) $attachment->getSize(),
            'sha256' => $path === false ? '' : (string) hash_file('sha256', $path),
        ]];
    }

    /**
     * @return array{disk: string, path: string}
     */
    private function store(UploadedFile $attachment): array
    {
        $disk = (string) config('filesystems.media_disk', 'local');
        $path = $attachment->storeAs('media/'.now()->format('Y/m'), (string) Str::uuid(), ['disk' => $disk]);

        if ($path === false) {
            throw new ConversationConflict('No se pudo guardar el archivo adjunto. Inténtalo de nuevo.');
        }

        return ['disk' => $disk, 'path' => $path];
    }

    /**
     * The name shown to the team and sent to the customer for documents:
     * no directories, control characters or reserved symbols.
     */
    private function safeName(string $original, MessageType $type): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', basename($original)) ?? '';
        $name = trim(Str::limit($name, 180, ''));

        return $name !== '' && $name !== '.' && $name !== '..' ? $name : $type->value;
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
