<?php

namespace App\Actions\WebChat;

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageType;
use App\Events\Conversations\ConversationChanged;
use App\Exceptions\ConversationConflict;
use App\Exceptions\StorageUnavailable;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MediaFileName;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Services\WebChat\VisitorFiles;
use App\Services\WebChat\VisitorSessions;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A message written by a web chat visitor enters the SAME pipeline as a
 * WhatsApp message (ReceiveInboundMessage): contact registration, joining,
 * reopening or opening and routing the conversation, automatic assignment,
 * notifications and real-time signals. Nothing here duplicates that logic.
 *
 * The provider id is built on the server, 'web.in.{session}.{client uuid}',
 * so a retried send is stored once (deduplicated by the pipeline) and two
 * sessions can never collide; the time is the server's. The visitor's
 * pre-chat email only fills an empty contact email: what employees edited
 * is never overwritten.
 *
 * A file (handleFile) is an image, video, audio or document message whose
 * attachment the pipeline registers as pending, like a WhatsApp file whose
 * download is queued. The upload is then stored right away through
 * MediaDisk::putNamedFile in the contact's folder (MediaPaths: "Chat web /
 * contact / month / dated name"), so it lands on the media disk (the NAS
 * when mounted) exactly like inbound WhatsApp media, and the attachment
 * becomes available. A media disk that is not ready refuses the upload
 * before anything is recorded (503, the widget retries); a write that fails
 * afterwards leaves the attachment unavailable ("upload_failed") and the
 * same client id sent again stores the file into that same message.
 */
class ReceiveVisitorMessage
{
    public function __construct(
        private ReceiveInboundMessage $receiveInbound,
        private ConnectionMonitor $monitor,
        private VisitorSessions $sessions,
        private MediaDisk $mediaDisk,
        private MediaPaths $paths,
    ) {}

    /**
     * @return array{message: Message, created: bool}
     *
     * @throws NotFoundHttpException when the connection was deleted.
     * @throws ConversationConflict 'widget_unavailable' when it is deactivated or not configured,
     *                              'conflict' when identify() changed the session meanwhile.
     * @throws AuthenticationException when the session was revoked meanwhile.
     */
    public function handle(WebChatSession $session, string $clientMessageId, string $body): array
    {
        return $this->receive($session, $clientMessageId, MessageType::Text, $body);
    }

    /**
     * A file the visitor sent from the widget, already validated
     * (StoreVisitorFileRequest: its kind and real type).
     *
     * @param  array{kind: string, mime: string}  $inspected
     * @return array{message: Message, created: bool}
     *
     * @throws NotFoundHttpException when the connection was deleted.
     * @throws ConversationConflict 'widget_unavailable' as handle(); 'files_disabled' when the widget does not accept files.
     * @throws AuthenticationException when the session was revoked meanwhile.
     * @throws StorageUnavailable when the media disk cannot keep the file (nothing recorded when it was not ready).
     */
    public function handleFile(WebChatSession $session, string $clientMessageId, UploadedFile $file, array $inspected, ?string $caption = null): array
    {
        if (! (bool) WebChatWidget::query()->where('connection_id', $session->connection_id)->value('allow_visitor_files')) {
            throw new ConversationConflict('files_disabled');
        }

        // Nothing is recorded while the file could not be kept.
        $this->mediaDisk->assertReady();

        $fileName = MediaFileName::sanitize($file->getClientOriginalName());
        $content = [
            'source' => ReceiveInboundMessage::UPLOADED,
            'mime_type' => $inspected['mime'],
            'filename' => $fileName,
            'file_size' => (int) $file->getSize(),
        ];

        $received = $this->receive($session, $clientMessageId, VisitorFiles::messageType($inspected['kind']), $caption, $content);

        $this->storeUpload($received['message'], $file, $inspected);

        return $received;
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array{message: Message, created: bool}
     */
    private function receive(WebChatSession $session, string $clientMessageId, MessageType $type, ?string $body, ?array $content = null): array
    {
        $connection = $session->connection;

        if ($connection->isDeleted()) {
            throw new NotFoundHttpException;
        }

        if (! $connection->is_active || ! $connection->isConfigured()) {
            throw new ConversationConflict('widget_unavailable');
        }

        // identify() may have changed (or replaced) the session since the
        // middleware read it: the message belongs to the identity it has now.
        $visitorId = WebChatSession::query()->whereKey($session->id)->whereNull('revoked_at')->value('visitor_id');

        if (! is_string($visitorId)) {
            throw new AuthenticationException;
        }

        $profile = is_array($session->profile) ? $session->profile : [];
        $now = CarbonImmutable::now();

        $sender = new InboundSender(
            channel: ConnectionChannel::WebChat,
            provider: ConnectionProvider::WebWidget,
            externalId: $visitorId,
            profileName: is_string($profile['name'] ?? null) ? $profile['name'] : null,
            messageId: VisitorSessions::WIDGET_PREFIX.$session->public_id.'.'.strtolower($clientMessageId),
            occurredAt: $now,
        );

        $receipt = $this->receiveInbound->handle($connection, new InboundMessage('', $sender, $type, $body, $content));
        $created = $receipt->outcome !== InboundMessageOutcome::Duplicate;

        if ($created) {
            $this->monitor->inboundReceived($connection, $now);
        }

        $contact = $receipt->registration->contact;

        if ($session->contact_id !== $contact->id) {
            $this->attachContact($session, $visitorId, $contact->id);
        }

        $email = $profile['email'] ?? null;

        if (is_string($email) && $email !== '' && $contact->email === null) {
            Contact::query()->whereKey($contact->id)->whereNull('email')->update(['email' => Str::limit($email, 191, '')]);
        }

        return ['message' => $receipt->message, 'created' => $created];
    }

    /**
     * Stores the upload in the contact's folder and makes the attachment
     * available. Only an attachment that is not available yet is written
     * (a retried send whose first copy was stored changes nothing); the row
     * is settled under a lock, so a concurrent retry never leaves two
     * copies referenced.
     *
     * @param  array{kind: string, mime: string}  $inspected
     *
     * @throws StorageUnavailable
     */
    private function storeUpload(Message $message, UploadedFile $file, array $inspected): void
    {
        $attachment = MessageAttachment::query()->where('message_id', $message->id)->orderBy('id')->first();

        if ($attachment === null || $attachment->status === AttachmentStatus::Available) {
            return;
        }

        $localPath = (string) $file->getRealPath();

        try {
            $placement = $this->paths->forMessage($message, $inspected['mime'], $attachment->file_name, $inspected['kind'])
                ?? throw new StorageUnavailable('write_failed', $this->mediaDisk->name());
            $stored = $this->mediaDisk->putNamedFile($localPath, $placement['directory'], $placement['stem'], $placement['extension']);
        } catch (StorageUnavailable $exception) {
            $this->markFailed($attachment, $message);

            throw $exception;
        }

        try {
            $recorded = DB::transaction(function () use ($attachment, $stored, $inspected, $localPath): bool {
                $current = MessageAttachment::query()->lockForUpdate()->find($attachment->id);

                if ($current === null || $current->status === AttachmentStatus::Available) {
                    return false;
                }

                $current->forceFill([
                    'disk' => $stored['disk'],
                    'path' => $stored['path'],
                    'mime_type' => $inspected['mime'],
                    'size_bytes' => (int) filesize($localPath),
                    'sha256' => (string) hash_file('sha256', $localPath),
                    'status' => AttachmentStatus::Available,
                    'error_code' => null,
                    'downloaded_at' => now(),
                ])->save();

                return true;
            });
        } catch (Throwable $exception) {
            $this->mediaDisk->discard($stored['disk'], $stored['path']);
            $this->markFailed($attachment, $message);

            throw $exception;
        }

        if (! $recorded) {
            // Another attempt already stored it: this copy is unreferenced.
            $this->mediaDisk->discard($stored['disk'], $stored['path']);

            return;
        }

        // The conversation shows the file as soon as it is stored.
        ConversationChanged::dispatch($message->conversation_id, 'message', messageId: $message->id);
    }

    /**
     * The file could not be stored: the message keeps saying it had one.
     */
    private function markFailed(MessageAttachment $attachment, Message $message): void
    {
        $updated = MessageAttachment::query()
            ->whereKey($attachment->id)
            ->where('status', '!=', AttachmentStatus::Available)
            ->update(['status' => AttachmentStatus::Unavailable, 'error_code' => 'upload_failed', 'updated_at' => now()]);

        if ($updated > 0) {
            ConversationChanged::dispatch($message->conversation_id, 'message', messageId: $message->id);
        }
    }

    /**
     * Binds the contact to the session only while it still has the identity
     * the message was recorded with: when identify() won the race, the
     * anonymous contact is never attached to the identified session (409).
     * An identified person's other sessions get the contact too.
     *
     * @throws ConversationConflict
     */
    private function attachContact(WebChatSession $session, string $visitorId, int $contactId): void
    {
        $sameIdentity = WebChatSession::query()
            ->whereKey($session->id)
            ->where('visitor_id', $visitorId)
            ->whereNull('revoked_at');

        $attached = (clone $sameIdentity)->update(['contact_id' => $contactId]) > 0
            || (clone $sameIdentity)->where('contact_id', $contactId)->exists();

        if (! $attached) {
            throw new ConversationConflict('conflict');
        }

        $session->forceFill(['contact_id' => $contactId])->syncOriginalAttribute('contact_id');
        $this->sessions->attachIdentifiedContact($session->connection_id, $visitorId, $contactId);
    }
}
