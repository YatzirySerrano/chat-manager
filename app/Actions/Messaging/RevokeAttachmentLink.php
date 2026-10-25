<?php

namespace App\Actions\Messaging;

use App\Enums\AttachmentDeliveryMethod;
use App\Enums\AuditAction;
use App\Enums\MessageDirection;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Attachments\AttachmentDownloadLink;
use Illuminate\Support\Facades\DB;

/**
 * Turns off, before it expires, the secure download link a customer received
 * for a file (e.g. a ZIP sent to the wrong person): stores
 * metadata.download_link.revoked_at on the message, after which the public
 * route answers 410 and no transport sends that link again. Revoking twice
 * changes nothing. Authorization (the sender or anyone who can reply in the
 * conversation) is checked by the controller; every revocation is audited.
 */
class RevokeAttachmentLink
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Conversation $conversation, Message $message, MessageAttachment $attachment, User $actor): Message
    {
        return DB::transaction(function () use ($conversation, $message, $attachment, $actor): Message {
            $current = Message::query()->lockForUpdate()->findOrFail($message->id);

            if ($current->conversation_id !== $conversation->id || $attachment->message_id !== $current->id
                || $current->direction !== MessageDirection::Outbound || $attachment->delivery_method !== AttachmentDeliveryMethod::Link) {
                throw new ConversationConflict('Este archivo no se envió como enlace de descarga.');
            }

            if (AttachmentDownloadLink::isRevoked($current)) {
                return $current;
            }

            $metadata = is_array($current->metadata) ? $current->metadata : [];
            $link = is_array($metadata[AttachmentDownloadLink::METADATA_KEY] ?? null) ? $metadata[AttachmentDownloadLink::METADATA_KEY] : [];

            $current->forceFill(['metadata' => [...$metadata, AttachmentDownloadLink::METADATA_KEY => [
                ...$link,
                'revoked_at' => now()->toIso8601String(),
                'revoked_by_user_id' => $actor->id,
            ]]])->save();

            $this->audit->record(AuditAction::AttachmentLinkRevoked, $conversation, [
                'message_id' => $current->id,
                'attachment_id' => $attachment->id,
            ], $actor);

            ConversationChanged::dispatch($conversation->id, 'message');

            return $current;
        });
    }
}
