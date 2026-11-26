<?php

namespace App\Actions\Messaging;

use App\Enums\AuditAction;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\WhatsAppTransportUnavailable;
use App\Services\WhatsAppWeb\WhatsAppWebTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit or "delete for everyone" a message the team sent through a WhatsApp
 * connection linked by QR (WhatsApp Web), as the WhatsApp apps allow: the
 * change is sent to WhatsApp first and only recorded once it was accepted.
 *
 * Within WhatsApp's own windows: a text can be edited for EDIT_MINUTES
 * after it was sent, a message deleted for DELETE_HOURS. The previous texts
 * stay in metadata.edit_history and both changes are audited; a deleted
 * message keeps its row (revoked_at), like one the customer deletes.
 * The Cloud API (Meta) offers neither, so only QR connections qualify.
 */
class ChangeSentMessage
{
    public const EDIT_MINUTES = 15;

    public const DELETE_HOURS = 48;

    public function __construct(private WhatsAppWebTransport $transport, private AuditLogger $audit) {}

    /**
     * Whether the message is one the team sent by QR and WhatsApp still has.
     */
    public static function changeable(Message $message): bool
    {
        $connection = $message->relationLoaded('conversation') ? $message->conversation->channelConnection : null;

        return $message->direction === MessageDirection::Outbound
            && $message->revoked_at === null
            && in_array($message->status, [MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read], true)
            && is_string($message->whatsapp_message_id)
            && str_starts_with($message->whatsapp_message_id, 'wweb.')
            && ($connection === null || $connection->isWhatsAppWeb());
    }

    public static function editable(Message $message): bool
    {
        $sentAt = $message->sent_at ?? $message->created_at;

        return self::changeable($message)
            && $message->type === MessageType::Text
            && $sentAt !== null
            && $sentAt->greaterThan(now()->subMinutes(self::EDIT_MINUTES));
    }

    public static function deletable(Message $message): bool
    {
        $sentAt = $message->sent_at ?? $message->created_at;

        return self::changeable($message)
            && $sentAt !== null
            && $sentAt->greaterThan(now()->subHours(self::DELETE_HOURS));
    }

    public function edit(Conversation $conversation, Message $message, string $text, User $actor): Message
    {
        $text = trim($text);

        if (! self::editable($message)) {
            throw ValidationException::withMessages(['body' => 'WhatsApp solo permite editar mensajes de texto enviados hace menos de '.self::EDIT_MINUTES.' minutos.']);
        }

        if ($text === '' || $text === trim((string) $message->body)) {
            throw ValidationException::withMessages(['body' => 'Escribe el nuevo texto del mensaje.']);
        }

        $this->send($message, 'edit', $text);

        DB::transaction(function () use ($message, $text): void {
            $metadata = $message->metadata ?? [];
            $history = is_array($metadata['edit_history'] ?? null) ? $metadata['edit_history'] : [];
            $history[] = ['body' => $message->body, 'replaced_at' => now()->toIso8601String()];
            $metadata['edit_history'] = array_slice($history, -10);

            $message->forceFill(['body' => $text, 'edited_at' => now(), 'metadata' => $metadata])->save();
        });

        $this->audit->record(AuditAction::MessageEdited, $conversation, ['message_id' => $message->id], $actor);
        ConversationChanged::dispatch($conversation->id, 'message', messageId: $message->id);

        return $message;
    }

    public function delete(Conversation $conversation, Message $message, User $actor): Message
    {
        if (! self::deletable($message)) {
            throw ValidationException::withMessages(['message' => 'WhatsApp solo permite eliminar para todos los mensajes enviados en las últimas '.self::DELETE_HOURS.' horas.']);
        }

        $this->send($message, 'delete');

        $message->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(AuditAction::MessageDeletedForEveryone, $conversation, ['message_id' => $message->id], $actor);
        ConversationChanged::dispatch($conversation->id, 'message', messageId: $message->id);

        return $message;
    }

    private function send(Message $message, string $kind, ?string $text = null): void
    {
        try {
            $this->transport->modify($message, $kind, $text);
        } catch (WhatsAppTransportUnavailable|MetaGraphException $exception) {
            throw ValidationException::withMessages(['message' => 'WhatsApp no aceptó el cambio: '.$exception->getMessage()]);
        }
    }
}
