<?php

namespace App\Actions\Conversations;

use App\Actions\Contacts\RegisterInboundContact;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Enums\InboundContactOutcome;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DownloadInboundMedia;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\ConversationRouter;
use App\Services\Meta\MediaFileName;
use App\Support\InboundContactRegistration;
use App\Support\InboundMessage;
use App\Support\InboundMessageReceipt;
use App\Support\ReadCommittedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Receives one customer message, independently of how it arrived (webhook,
 * job, test), without any web session:
 *
 * 1. registers or recovers the sender in the directory (RegisterInboundContact);
 * 2. joins the contact's unclosed conversation on that connection, or
 *    reopens the last closed one when the connection's reopening window
 *    allows it, or opens a new one routed by configuration;
 * 3. stores the message once (the provider id is unique), so repeated
 *    deliveries change nothing;
 * 4. moves the activity dates forward only (late events never move them
 *    back) and links the provider's referral to the conversation;
 * 5. distributes the conversation when its department does so automatically;
 * 6. registers the media it announces as a pending attachment and queues
 *    its download (DownloadInboundMedia), never inside this transaction:
 *    a WhatsApp media id, or the CDN URL of a Messenger/Instagram attachment.
 *
 * Every channel (WhatsApp, Messenger, Instagram, web chat) enters through
 * here; the provider message id is unique across channels (wamid, Meta
 * "mid", web.in.*), see MetaMessagingPayload::messageId().
 *
 * A repeated delivery of an already stored message is answered before the
 * directory is touched, so duplicates never update the contact's
 * interaction data. If storing the message fails after the contact was
 * registered, the webhook job retries the whole receipt; registration is
 * idempotent.
 *
 * Two messages of the same contact processed at once race on the database's
 * single-unclosed-conversation key: the loser retries and joins the
 * winner's conversation. The receipt runs at READ COMMITTED with bounded,
 * logged deadlock retries (ReadCommittedTransaction). Conversations of different connections are never mixed.
 */
class ReceiveInboundMessage
{
    public function __construct(
        private RegisterInboundContact $registerContact,
        private ConversationLifecycle $lifecycle,
        private ConversationRouter $router,
        private ReopenConversation $reopenConversation,
        private AssignAutomatically $assignAutomatically,
        private ReadCommittedTransaction $transaction,
    ) {}

    public function handle(Connection $connection, InboundMessage $inbound): InboundMessageReceipt
    {
        $duplicate = $this->duplicate($connection, $inbound);

        if ($duplicate !== null) {
            return $duplicate;
        }

        $registration = $this->registerContact->handle($connection, $inbound->sender);

        // At READ COMMITTED (no gap locks) only messages of the same contact
        // contend; deadlocks left are retried with a pause, and a unique
        // violation means another worker opened the conversation or stored
        // this message first.
        $receive = fn (): InboundMessageReceipt => $this->receive($connection, $inbound, $registration);

        try {
            return $this->transaction->run('inbound.message', $receive);
        } catch (UniqueConstraintViolationException) {
            return $this->transaction->run('inbound.message', $receive);
        }
    }

    private function receive(Connection $connection, InboundMessage $inbound, InboundContactRegistration $registration): InboundMessageReceipt
    {
        $stored = Message::query()->where('whatsapp_message_id', $inbound->sender->messageId)->first();

        if ($stored !== null) {
            return new InboundMessageReceipt($registration, Conversation::query()->findOrFail($stored->conversation_id), $stored, InboundMessageOutcome::Duplicate);
        }

        $contact = $registration->contact;
        $sentAt = $inbound->sender->occurredAt;

        [$conversation, $outcome] = $this->conversationFor($connection, $contact, $sentAt);

        $message = new Message([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $inbound->sender->messageId,
            'direction' => MessageDirection::Inbound,
            'type' => $inbound->type,
            'status' => MessageStatus::Received,
            'body' => $inbound->body,
            'reply_to_message_id' => $this->repliedMessageId($conversation, $inbound),
            'metadata' => array_filter([
                'content' => $inbound->content,
                'context' => $inbound->context,
                'reply_to' => is_string($inbound->context['id'] ?? null) ? ['message_id' => $inbound->context['id']] : null,
                'profile_name' => $inbound->sender->profileName,
                // PSID / IGSID: scoped to this page or Instagram account, so
                // replies of this conversation go back to this exact id.
                'sender_external_id' => in_array($connection->channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram], true) ? $inbound->sender->externalId : null,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $message->forceFill(['sent_at' => $sentAt])->save();

        $attachment = $this->registerMedia($message, $inbound);

        $conversation->forceFill([
            'last_inbound_at' => $this->latest($conversation->last_inbound_at, $sentAt),
            'last_message_at' => $this->latest($conversation->last_message_at, $sentAt),
        ])->save();

        $this->linkReferral($registration->attribution, $conversation);

        if ($outcome === InboundMessageOutcome::Created) {
            $this->assignAutomatically->handle($conversation);
        }

        // One event per message: its real-time listener already refreshes the
        // conversation and every inbox that lists it.
        MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Inbound->value);

        if ($attachment !== null) {
            DownloadInboundMedia::dispatch($attachment->id)->afterCommit();
        }

        return new InboundMessageReceipt($registration, $conversation->refresh(), $message, $outcome);
    }

    /**
     * A message already stored is answered without registering the sender
     * again (a repeated delivery must not touch the directory).
     */
    private function duplicate(Connection $connection, InboundMessage $inbound): ?InboundMessageReceipt
    {
        $stored = Message::query()->where('whatsapp_message_id', $inbound->sender->messageId)->first();

        if ($stored === null) {
            return null;
        }

        $conversation = Conversation::query()->with('contact')->findOrFail($stored->conversation_id);
        $identity = ContactIdentity::query()
            ->where('channel', $inbound->sender->channel)
            ->where('external_id', $inbound->sender->externalId)
            ->first();

        if ($identity === null || $identity->contact_id !== $conversation->contact_id) {
            return null;
        }

        return new InboundMessageReceipt(
            new InboundContactRegistration($conversation->contact, $identity, $connection, InboundContactOutcome::Matched),
            $conversation,
            $stored,
            InboundMessageOutcome::Duplicate,
        );
    }

    /**
     * The customer replied to a stored message (Meta's context id).
     */
    private function repliedMessageId(Conversation $conversation, InboundMessage $inbound): ?int
    {
        $id = $inbound->context['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $replied = Message::query()
            ->where('whatsapp_message_id', $id)
            ->whereHas('conversation', fn ($query) => $query
                ->where('connection_id', $conversation->connection_id)
                ->where('contact_id', $conversation->contact_id))
            ->value('id');

        return is_int($replied) ? $replied : null;
    }

    /**
     * Media announced by Meta becomes a pending attachment; the file is
     * downloaded later by a dedicated job.
     */
    private function registerMedia(Message $message, InboundMessage $inbound): ?MessageAttachment
    {
        if (! in_array($inbound->type, [MessageType::Image, MessageType::Audio, MessageType::Video, MessageType::Document, MessageType::Sticker], true)) {
            return null;
        }

        $mediaId = $inbound->content['id'] ?? null;
        $sourceUrl = ($inbound->content['source'] ?? null) === 'url' && is_string($inbound->content['url'] ?? null) ? $inbound->content['url'] : null;

        // WhatsApp announces a media id; Messenger and Instagram a URL of
        // Meta's CDN, kept in the message content for the download job.
        if ((! is_string($mediaId) || $mediaId === '') && $sourceUrl === null) {
            return null;
        }

        $mime = is_string($inbound->content['mime_type'] ?? null) ? Str::limit(trim(explode(';', $inbound->content['mime_type'])[0]), 127, '') : 'application/octet-stream';
        $fileName = is_string($inbound->content['filename'] ?? null) ? MediaFileName::sanitize($inbound->content['filename']) : null;

        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $message->id,
            'whatsapp_media_id' => is_string($mediaId) && $mediaId !== '' ? Str::limit($mediaId, 128, '') : null,
            'disk' => (string) config('filesystems.media_disk', 'local'),
            'path' => null,
            'mime_type' => $mime,
            'file_name' => $fileName,
            'sha256' => is_string($inbound->content['sha256'] ?? null) && strlen($inbound->content['sha256']) <= 64 ? $inbound->content['sha256'] : null,
            'status' => AttachmentStatus::Pending,
        ])->save();

        return $attachment;
    }

    /**
     * @return array{Conversation, InboundMessageOutcome}
     */
    private function conversationFor(Connection $connection, Contact $contact, CarbonImmutable $sentAt): array
    {
        $unclosed = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->notClosed()
            ->lockForUpdate()
            ->first();

        if ($unclosed !== null) {
            return [$unclosed, InboundMessageOutcome::Appended];
        }

        $lastClosed = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::Closed)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($lastClosed !== null && $lastClosed->closed_at !== null) {
            if ($sentAt->lessThanOrEqualTo($lastClosed->closed_at)) {
                return [$lastClosed, InboundMessageOutcome::LateArrival];
            }

            $window = $connection->reopen_window_minutes;

            if ($window !== null && $sentAt->lessThanOrEqualTo($lastClosed->closed_at->addMinutes($window))) {
                return [$this->reopenConversation->reopen($lastClosed, null, 'inbound'), InboundMessageOutcome::Reopened];
            }
        }

        ['department' => $department, 'routed_by' => $routedBy] = $this->router->route($connection);

        // An automatic department is locked before the conversation row is
        // inserted: the insert's foreign key check takes a shared lock on the
        // department, and upgrading it later in AssignAutomatically
        // deadlocked simultaneous arrivals (docs/rendimiento-y-carga.md).
        if ($department?->distribution_mode === DistributionMode::Automatic) {
            Department::query()->whereKey($department->id)->lockForUpdate()->value('id');
        }

        $conversation = new Conversation([
            'connection_id' => $connection->id,
            'contact_id' => $contact->id,
            'department_id' => $department?->id,
        ]);
        $conversation->save();

        $this->lifecycle->record($conversation, ConversationEventType::Created, null, [
            'to_department_id' => $department?->id,
            'metadata' => ['source' => 'inbound', 'routed_by' => $routedBy],
        ]);

        return [$conversation, InboundMessageOutcome::Created];
    }

    /**
     * The referral that came with the message describes how this
     * conversation started; it is linked once and never moved.
     */
    private function linkReferral(?ContactAttribution $attribution, Conversation $conversation): void
    {
        if ($attribution !== null && $attribution->conversation_id === null) {
            ContactAttribution::query()
                ->whereKey($attribution->id)
                ->whereNull('conversation_id')
                ->update(['conversation_id' => $conversation->id]);
        }
    }

    private function latest(?\DateTimeInterface $current, CarbonImmutable $candidate): \DateTimeInterface
    {
        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
