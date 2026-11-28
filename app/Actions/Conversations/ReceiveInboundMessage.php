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
use App\Jobs\DownloadTelegramMedia;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Jobs\SendAwayInOpenConversation;
use App\Jobs\SendChatbotKeywordReply;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Ai\AiAssistant;
use App\Services\Chatbot\ChatbotConversations;
use App\Services\Chatbot\ChatbotKeywords;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\ConversationRouter;
use App\Services\Conversations\QueueRules;
use App\Services\Conversations\RequestSubmissionCapture;
use App\Services\Conversations\ResponseClock;
use App\Services\Meta\MediaFileName;
use App\Services\Telegram\TelegramBotClient;
use App\Services\WhatsAppWeb\WhatsAppWebAttachments;
use App\Support\InboundContactRegistration;
use App\Support\InboundMessage;
use App\Support\InboundMessageReceipt;
use App\Support\ReadCommittedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

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
 *    back) and links the provider's referral to the conversation; a credit
 *    request written in the text fills «Datos de la solicitud» while the
 *    conversation has no client yet (RequestSubmissionCapture, best-effort);
 * 5. distributes the conversation when its department does so automatically;
 *    a request waiting for the customer's missing information comes back to
 *    «Pendientes» with a new queue clock (ResumeAwaitingInformation);
 * 6. registers the media it announces as a pending attachment and queues
 *    its download (DownloadInboundMedia), never inside this transaction:
 *    a WhatsApp media id, the CDN URL of a Messenger/Instagram attachment,
 *    or the descriptor of a WhatsApp Web (QR) file, which is kept encrypted
 *    on the attachment and downloaded by DownloadWhatsAppWebMedia. A file
 *    the customer uploaded to this platform (web chat, content source
 *    self::UPLOADED) is registered pending too, but no download is queued:
 *    the caller stores it right after the receipt (ReceiveVisitorMessage).
 *    A Telegram file keeps its file_id encrypted on the attachment and is
 *    downloaded by DownloadTelegramMedia.
 *
 * Every channel (WhatsApp, Messenger, Instagram, web chat, Telegram) enters
 * through here; the provider message id is unique across channels (wamid,
 * Meta "mid", web.in.*, tg.*), see MetaMessagingPayload::messageId().
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
    /** Content source of a file uploaded to this platform by the customer (web chat). */
    public const UPLOADED = 'upload';

    public function __construct(
        private RegisterInboundContact $registerContact,
        private ConversationLifecycle $lifecycle,
        private ConversationRouter $router,
        private ReopenConversation $reopenConversation,
        private AssignAutomatically $assignAutomatically,
        private ReadCommittedTransaction $transaction,
        private ChatbotConversations $chatbot,
        private ResumeAwaitingInformation $resumeAwaitingInformation,
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

        [$conversation, $outcome, $menuOffered] = $this->conversationFor($connection, $contact, $sentAt);

        $message = new Message([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $inbound->sender->messageId,
            'direction' => MessageDirection::Inbound,
            'type' => $inbound->type,
            'status' => MessageStatus::Received,
            'body' => $inbound->body,
            'reply_to_message_id' => $this->repliedMessageId($conversation, $inbound),
            'metadata' => array_filter([
                'content' => self::storedContent($inbound->content),
                'context' => $inbound->context,
                // Who wrote the quoted message and an excerpt of it, when the provider sent them
                // (WhatsApp Web): the quote is drawn even if the original was never stored here.
                'reply_to' => is_string($inbound->context['id'] ?? null) ? array_filter([
                    'message_id' => $inbound->context['id'],
                    'from_me' => is_bool($inbound->context['from_me'] ?? null) ? $inbound->context['from_me'] : null,
                    'preview' => is_string($inbound->context['preview'] ?? null) ? $inbound->context['preview'] : null,
                ], fn (mixed $value): bool => $value !== null) : null,
                'profile_name' => $inbound->sender->profileName,
                // PSID / IGSID: scoped to this page or Instagram account, so
                // replies of this conversation go back to this exact id; the
                // Telegram user id is the bot's private chat with the person.
                'sender_external_id' => in_array($connection->channel, [ConnectionChannel::Messenger, ConnectionChannel::Instagram, ConnectionChannel::Telegram], true) ? $inbound->sender->externalId : null,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $message->forceFill(['sent_at' => $sentAt])->save();

        $attachment = $this->registerMedia($message, $inbound);

        $conversation->forceFill([
            'last_inbound_at' => $this->latest($conversation->last_inbound_at, $sentAt),
            'last_message_at' => $this->latest($conversation->last_message_at, $sentAt),
        ])->save();

        $this->linkReferral($registration->attribution, $conversation);

        // A credit request written by the field manager («Cliente: …»,
        // «Solicito autorización …») fills «Datos de la solicitud» while the
        // conversation has no client yet (RequestSubmissionCapture), on this
        // locked row. Best-effort: it never stops nor fails the receipt.
        if ($message->type === MessageType::Text && $message->body !== null) {
            try {
                app(RequestSubmissionCapture::class)->fromMessage($conversation, $message);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // The customer waits for a human answer from now on (response cycle).
        app(ResponseClock::class)->customerWrote($conversation, $sentAt);

        // A request returned for missing information («Esperando información
        // del cliente») comes back to «Pendientes» with a new queue clock, in
        // this same conversation (its row is locked since conversationFor()).
        if (in_array($outcome, [InboundMessageOutcome::Appended, InboundMessageOutcome::Reopened], true)) {
            $this->resumeAwaitingInformation->handle($conversation);
        }

        // While the chatbot's welcome menu waits for the customer's choice,
        // the conversation is not distributed (ChatbotConversations).
        if ($outcome === InboundMessageOutcome::Created && ! $menuOffered) {
            $this->assignAutomatically->handle($conversation);
        }

        // The answer to the welcome menu routes the conversation (and is not
        // answered by a keyword rule).
        $answeredByMenu = $outcome === InboundMessageOutcome::Appended && $this->chatbot->answer($conversation, $message);

        // One event per message: its real-time listener already refreshes the
        // conversation and every inbox that lists it.
        MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Inbound->value);

        // A customer writing again in an open or queued conversation may get
        // the away message once per period outside hours, only where the
        // connection's administrator turned it on.
        if ($outcome === InboundMessageOutcome::Appended && $connection->away_in_open_conversations) {
            SendAwayInOpenConversation::dispatch($conversation->id, $message->id)->afterCommit();
        }

        // «Chatbot · Palabras clave»: matched and answered on the messages
        // queue, never inside this receipt; only for text a customer just
        // wrote in a live conversation that is not choosing from the menu.
        if (! $menuOffered && ! $answeredByMenu
            && in_array($outcome, [InboundMessageOutcome::Created, InboundMessageOutcome::Appended, InboundMessageOutcome::Reopened], true)
            && $message->type === MessageType::Text
            && ChatbotKeywords::normalize((string) $message->body) !== ''
            && SendChatbotKeywordReply::anyRule()) {
            SendChatbotKeywordReply::dispatch($conversation->id, $message->id)->afterCommit();
        }

        // «Inteligencia artificial»: answered on its own queue a few seconds
        // later, only while the conversation waits in a queue (AiAssistant).
        if (in_array($outcome, [InboundMessageOutcome::Created, InboundMessageOutcome::Appended, InboundMessageOutcome::Reopened], true)) {
            app(AiAssistant::class)->afterInbound($connection, $conversation, $message, $menuOffered || $answeredByMenu);
        }

        if ($attachment !== null && $attachment->status === AttachmentStatus::Pending && ($inbound->content['source'] ?? null) !== self::UPLOADED) {
            // A WhatsApp Web file is fetched from the private service and a
            // Telegram file from the Bot API, never from Meta.
            match (true) {
                $connection->isTelegram() => DownloadTelegramMedia::dispatch($attachment->id)->afterCommit(),
                $attachment->provider_reference !== null => DownloadWhatsAppWebMedia::dispatch($attachment->id)->afterCommit(),
                default => DownloadInboundMedia::dispatch($attachment->id)->afterCommit(),
            };
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

        // WhatsApp Web (QR): what the private service needs to download and
        // decrypt the file. It is kept encrypted on the attachment and never
        // in the message content, which the browser may read.
        $descriptor = ($inbound->content['source'] ?? null) === 'whatsapp_web' && is_array($inbound->content['descriptor'] ?? null) && $inbound->content['descriptor'] !== []
            ? $inbound->content['descriptor']
            : null;

        // A WhatsApp Web file, with or without its descriptor: without one
        // (no download key came, as with "view once" media) the message
        // still shows that it had a file, as unavailable.
        if (($inbound->content['source'] ?? null) === 'whatsapp_web') {
            return WhatsAppWebAttachments::create($message, $inbound->content ?? [], $descriptor);
        }

        // Telegram: the file_id is kept encrypted on the attachment (it is
        // useless without the bot token, but never needs to reach the browser).
        if (($inbound->content['source'] ?? null) === 'telegram') {
            return self::telegramAttachment($message, $inbound->content ?? [], $descriptor ?? (is_array($inbound->content['descriptor'] ?? null) ? $inbound->content['descriptor'] : null));
        }

        $uploaded = ($inbound->content['source'] ?? null) === self::UPLOADED;

        // WhatsApp announces a media id; Messenger and Instagram a URL of
        // Meta's CDN, kept in the message content for the download job. An
        // uploaded file is already here, waiting to be stored by the caller.
        if (! $uploaded && (! is_string($mediaId) || $mediaId === '') && $sourceUrl === null) {
            return null;
        }

        $mime = is_string($inbound->content['mime_type'] ?? null) ? Str::limit(trim(explode(';', $inbound->content['mime_type'])[0]), 127, '') : 'application/octet-stream';
        $fileName = is_string($inbound->content['filename'] ?? null) ? MediaFileName::sanitize($inbound->content['filename']) : null;

        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $message->id,
            'whatsapp_media_id' => ! $uploaded && is_string($mediaId) && $mediaId !== '' ? Str::limit($mediaId, 128, '') : null,
            'provider_reference' => null,
            'disk' => (string) config('filesystems.media_disk', 'local'),
            'path' => null,
            'mime_type' => $mime,
            'file_name' => $fileName,
            'size_bytes' => $uploaded && is_int($inbound->content['file_size'] ?? null) ? $inbound->content['file_size'] : null,
            'sha256' => is_string($inbound->content['sha256'] ?? null) && strlen($inbound->content['sha256']) <= 64 ? $inbound->content['sha256'] : null,
            'status' => AttachmentStatus::Pending,
        ])->save();

        return $attachment;
    }

    /**
     * The attachment of a Telegram file: pending with its descriptor, or
     * unavailable when Telegram sent no file_id or announced a file larger
     * than a bot may download (20 MB).
     *
     * @param  array<array-key, mixed>  $content
     * @param  array<array-key, mixed>|null  $descriptor
     */
    private static function telegramAttachment(Message $message, array $content, ?array $descriptor): MessageAttachment
    {
        $size = is_int($descriptor['file_size'] ?? null) ? $descriptor['file_size'] : null;
        $missing = match (true) {
            ! is_string($descriptor['file_id'] ?? null) => 'expired',
            $size !== null && $size > TelegramBotClient::DOWNLOAD_MAX_BYTES => 'too_large',
            default => null,
        };

        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $message->id,
            'whatsapp_media_id' => null,
            'provider_reference' => $missing === null ? $descriptor : null,
            'disk' => (string) config('filesystems.media_disk', 'local'),
            'path' => null,
            'mime_type' => WhatsAppWebAttachments::mime($content['mime_type'] ?? null),
            'file_name' => is_string($content['filename'] ?? null) ? MediaFileName::sanitize($content['filename']) : null,
            'width' => is_int($content['width'] ?? null) && is_int($content['height'] ?? null) ? $content['width'] : null,
            'height' => is_int($content['width'] ?? null) && is_int($content['height'] ?? null) ? $content['height'] : null,
            'size_bytes' => $size,
            'status' => $missing === null ? AttachmentStatus::Pending : AttachmentStatus::Unavailable,
            'error_code' => $missing,
        ])->save();

        return $attachment;
    }

    /**
     * Content kept with the message: everything the provider sent except
     * the download descriptor of a WhatsApp Web file (a decryption key).
     *
     * @param  array<array-key, mixed>|null  $content
     * @return array<array-key, mixed>|null
     */
    private static function storedContent(?array $content): ?array
    {
        if ($content === null || ! array_key_exists('descriptor', $content)) {
            return $content;
        }

        unset($content['descriptor']);

        return $content;
    }

    /**
     * The conversation, what happened to it and whether the chatbot's
     * welcome menu was opened for it (only for a new one).
     *
     * @return array{Conversation, InboundMessageOutcome, bool}
     */
    private function conversationFor(Connection $connection, Contact $contact, CarbonImmutable $sentAt): array
    {
        $unclosed = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->live()
            ->notClosed()
            ->lockForUpdate()
            ->first();

        if ($unclosed !== null) {
            return [$unclosed, InboundMessageOutcome::Appended, false];
        }

        // Imported history is never reopened nor receives a late message:
        // the customer's next message always starts (or continues) a live one.
        $lastClosed = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->live()
            ->where('status', ConversationStatus::Closed)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($lastClosed !== null && $lastClosed->closed_at !== null) {
            if ($sentAt->lessThanOrEqualTo($lastClosed->closed_at)) {
                return [$lastClosed, InboundMessageOutcome::LateArrival, false];
            }

            $window = $connection->reopen_window_minutes;

            // A request closed as completed is finished for good: the next
            // message is a new request and enters the waiting list. So is a
            // conversation closed as «No es solicitud» (MarkNotRequest).
            if ($window !== null && $lastClosed->completed_at === null && $lastClosed->not_request_at === null && $sentAt->lessThanOrEqualTo($lastClosed->closed_at->addMinutes($window))) {
                return [$this->reopenConversation->reopen($lastClosed, null, 'inbound'), InboundMessageOutcome::Reopened, false];
            }
        }

        ['department' => $department, 'routed_by' => $routedBy] = $this->router->route($connection, $contact);

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
        // Born «Necesita información» only where the confirmation rule is on
        // right now; otherwise ready, with its queue clock started (QueueRules).
        $conversation->forceFill(app(QueueRules::class)->born($department?->id))->save();

        // The chatbot's welcome menu, before the event its listeners read.
        $menuOffered = $this->chatbot->offer($connection, $conversation, $routedBy);

        $this->lifecycle->record($conversation, ConversationEventType::Created, null, [
            'to_department_id' => $department?->id,
            'metadata' => ['source' => 'inbound', 'routed_by' => $routedBy, ...($menuOffered ? ['chatbot_menu' => true] : [])],
        ]);

        return [$conversation, InboundMessageOutcome::Created, $menuOffered];
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
