<?php

namespace App\Services\WhatsAppWeb;

use App\Actions\Contacts\RegisterInboundContact;
use App\Actions\Conversations\ReceiveInboundMessage;
use App\Actions\Messaging\RecordMessageEdit;
use App\Actions\Messaging\RecordMessageReaction;
use App\Actions\Messaging\RecordMessageRevocation;
use App\Actions\Messaging\RecordMessageStatus;
use App\Actions\WhatsAppWeb\ImportWhatsAppWebHistory;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Jobs\ImportWhatsAppWebHistoryBatch;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\ProviderStatus;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Applies one event of the WhatsApp Web service (contract §5) to
 * ChatManager, reusing the existing pipeline instead of a parallel one:
 *
 * - session.state: the real state of the session (WhatsAppWebSessions);
 * - message.inbound: a customer message, received exactly like one of any
 *   other channel (ReceiveInboundMessage: contact, conversation, routing,
 *   notifications, real time, automatic messages). A reaction is not a
 *   message: it is kept on the message it reacts to (RecordMessageReaction);
 * - message.outbound_echo: something written on the linked phone itself,
 *   stored as an outbound message without author and without triggering
 *   automatic messages: in the conversation being attended (or the most
 *   recent one) of that contact and, when there is none, in the contact's
 *   WhatsApp history, so nothing written on the phone is lost and no
 *   request to attend is invented;
 * - message.status: delivery evidence of an outbound message
 *   (RecordMessageStatus);
 * - message.edited / message.revoked: the sender edited a message or
 *   deleted it for everyone; the stored message is updated or marked, never
 *   duplicated or erased (RecordMessageEdit, RecordMessageRevocation);
 * - history.batch: a slice of the chat history of a newly linked number,
 *   queued and stored apart as history (ImportWhatsAppWebHistory).
 *
 * Every step is idempotent: the stored message id is unique, a status only
 * moves forward and a state older than the stored one is ignored, so the
 * service may deliver the same event any number of times. Groups,
 * broadcasts, status updates and channels are never handled.
 */
class WhatsAppWebEvents
{
    public function __construct(
        private WhatsAppWebSessions $sessions,
        private ReceiveInboundMessage $receive,
        private RecordMessageStatus $recordStatus,
        private RecordMessageEdit $recordEdit,
        private RecordMessageRevocation $recordRevocation,
        private RecordMessageReaction $recordReaction,
        private RegisterInboundContact $registerContact,
        private ImportWhatsAppWebHistory $history,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return string What was done (for the log and the tests): applied, queued, duplicate or ignored.
     */
    public function handle(Connection $connection, string $type, array $data): string
    {
        return match ($type) {
            'session.state' => $this->sessionState($connection, $data),
            'message.inbound' => $this->inbound($connection, $data),
            'message.outbound_echo' => $this->outboundEcho($connection, $data),
            'message.status' => $this->status($connection, $data),
            'message.edited' => $this->edited($connection, $data),
            'message.revoked' => $this->revoked($connection, $data),
            'history.batch' => $this->historyBatch($connection, $data),
            default => 'ignored',
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function sessionState(Connection $connection, array $data): string
    {
        $this->sessions->apply($connection, $data);

        return 'applied';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function inbound(Connection $connection, array $data): string
    {
        if (($data['from_me'] ?? false) === true) {
            return $this->outboundEcho($connection, $data);
        }

        $payload = new WhatsAppWebMessagePayload($connection, $data);
        $externalId = $payload->externalId();

        if ($externalId === null) {
            return 'ignored';
        }

        if ($payload->type() === MessageType::Reaction) {
            return $this->reaction($connection, $payload);
        }

        try {
            $inbound = new InboundMessage(
                phoneNumberId: 'wweb-'.$connection->id,
                sender: new InboundSender(
                    channel: ConnectionChannel::Whatsapp,
                    provider: ConnectionProvider::WhatsappWeb,
                    externalId: $externalId,
                    profileName: $payload->profileName(),
                    messageId: $payload->storedId(),
                    occurredAt: $payload->occurredAt(),
                ),
                type: $payload->type(),
                body: $payload->body(),
                content: $payload->content(),
                context: self::context($payload),
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('WhatsApp Web message ignored: '.$exception->getMessage(), ['connection_id' => $connection->id]);

            return 'ignored';
        }

        $receipt = $this->receive->handle($connection, $inbound);

        if ($receipt->outcome === InboundMessageOutcome::Duplicate) {
            return 'duplicate';
        }

        $this->monitor->inboundReceived($connection, $inbound->sender->occurredAt);

        return 'applied';
    }

    /**
     * A reaction, of the customer or made on the linked phone: it joins the
     * message it reacts to. One to a message that was never stored, or one
     * that changes nothing, is ignored.
     */
    private function reaction(Connection $connection, WhatsAppWebMessagePayload $payload): string
    {
        $target = $payload->reactionTarget();

        if ($target === null) {
            return 'ignored';
        }

        try {
            $at = $payload->occurredAt();
        } catch (InvalidArgumentException) {
            return 'ignored';
        }

        return $this->recordReaction->handle($connection, $target, $payload->fromMe(), $payload->reactionEmoji(), $at) === null ? 'ignored' : 'applied';
    }

    /**
     * A message written on the linked phone. It joins the contact's
     * conversation on this connection (the one in attention, or the most
     * recent one) as an outbound message without an author. When the
     * contact has none (the business wrote first, from the phone), it is
     * kept in the contact's WhatsApp history conversation, which is closed
     * and read-only: the message is not lost and nothing appears in the
     * queues as if a customer were waiting.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function outboundEcho(Connection $connection, array $data): string
    {
        $payload = new WhatsAppWebMessagePayload($connection, $data);
        $externalId = $payload->externalId();

        if ($externalId === null) {
            return 'ignored';
        }

        if ($payload->type() === MessageType::Reaction) {
            return $this->reaction($connection, $payload);
        }

        try {
            $storedId = $payload->storedId();
            $sentAt = $payload->occurredAt();
        } catch (InvalidArgumentException) {
            return 'ignored';
        }

        if (Message::query()->where('whatsapp_message_id', $storedId)->exists()) {
            return 'duplicate';
        }

        $contactId = ContactIdentity::query()
            ->where('channel', ConnectionChannel::Whatsapp)
            ->where('external_id', $externalId)
            ->value('contact_id');

        if (! is_int($contactId)) {
            try {
                // Someone the business wrote to first: a provisional contact, like any new sender.
                $contactId = $this->registerContact->handle($connection, new InboundSender(
                    channel: ConnectionChannel::Whatsapp,
                    provider: ConnectionProvider::WhatsappWeb,
                    externalId: $externalId,
                    profileName: null,
                    messageId: $storedId,
                    occurredAt: $sentAt,
                ))->contact->id;
            } catch (InvalidArgumentException) {
                return 'ignored';
            }
        }

        try {
            $stored = DB::transaction(function () use ($connection, $contactId, $payload, $storedId, $sentAt): array {
                Contact::query()->whereKey($contactId)->lockForUpdate()->value('id');

                $conversation = Conversation::query()
                    ->where('connection_id', $connection->id)
                    ->where('contact_id', $contactId)
                    ->live()
                    ->orderByRaw('closed_at is null desc')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                $live = $conversation !== null;
                $conversation ??= $this->history->conversationFor($connection, $contactId, $sentAt, $sentAt);

                $content = $payload->content();
                $descriptor = is_array($content['descriptor'] ?? null) ? $content['descriptor'] : null;
                unset($content['descriptor']);
                $quote = $payload->quote();

                $message = new Message([
                    'conversation_id' => $conversation->id,
                    'whatsapp_message_id' => $storedId,
                    'direction' => MessageDirection::Outbound,
                    'type' => $payload->type(),
                    'status' => MessageStatus::Sent,
                    'body' => $payload->body(),
                    'reply_to_message_id' => $quote === null ? null : $this->repliedMessageId($connection, $contactId, $quote['message_id']),
                    'metadata' => array_filter([
                        // Written on the linked phone, not in ChatManager: it has no author here.
                        'sent_from_phone' => true,
                        'content' => $content === [] ? null : $content,
                        'reply_to' => $quote,
                    ], fn (mixed $value): bool => $value !== null),
                ]);
                $message->forceFill(['sent_at' => $sentAt, 'accepted_at' => $sentAt])->save();

                $attachment = in_array($payload->rawType(), WhatsAppWebMessagePayload::MEDIA_TYPES, true)
                    ? WhatsAppWebAttachments::create($message, $content ?? [], $descriptor)
                    : null;

                if ($live) {
                    $conversation->forceFill([
                        'last_outbound_at' => $conversation->last_outbound_at === null || $sentAt->greaterThan($conversation->last_outbound_at) ? $sentAt : $conversation->last_outbound_at,
                        'last_message_at' => $conversation->last_message_at === null || $sentAt->greaterThan($conversation->last_message_at) ? $sentAt : $conversation->last_message_at,
                    ])->save();
                } else {
                    $this->history->extend($conversation, $sentAt, $sentAt);
                }

                MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

                return ['download' => $descriptor === null ? null : $attachment?->id];
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        if ($stored['download'] !== null) {
            DownloadWhatsAppWebMedia::dispatch($stored['download']);
        }

        $this->monitor->outboundAccepted($connection);

        return 'applied';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function status(Connection $connection, array $data): string
    {
        $id = $data['provider_message_id'] ?? null;
        $status = $data['status'] ?? null;

        if (! is_string($id) || $id === '' || strlen($id) > 100 || ! is_string($status)) {
            return 'ignored';
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : null;

        $message = $this->recordStatus->handle($connection, new ProviderStatus(
            phoneNumberId: 'wweb-'.$connection->id,
            messageId: WhatsAppWebTransport::storedId($connection->id, $id),
            status: $status,
            occurredAt: WhatsAppWebMessagePayload::timestamp($data['timestamp'] ?? null),
            errors: $error === null ? [] : [[
                'code' => is_int($error['code'] ?? null) ? $error['code'] : null,
                'title' => 'WhatsApp no pudo entregar el mensaje.',
                'message' => is_string($error['message'] ?? null) ? Str::limit($error['message'], 300, '') : null,
                'details' => null,
            ]],
        ));

        return $message === null ? 'ignored' : 'applied';
    }

    /**
     * The sender edited a message: its stored text is replaced.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function edited(Connection $connection, array $data): string
    {
        $storedId = WhatsAppWebMessagePayload::storedIdOf($connection, $data['provider_message_id'] ?? null);

        if ($storedId === null || WhatsAppWebMessagePayload::externalIdOf($data['remote_jid'] ?? null) === null) {
            return 'ignored';
        }

        $text = is_string($data['text'] ?? null) ? $data['text'] : null;
        $message = $this->recordEdit->handle($connection, $storedId, $text, WhatsAppWebMessagePayload::timestamp($data['timestamp'] ?? null));

        return $message === null ? 'ignored' : 'applied';
    }

    /**
     * The sender deleted a message for everyone: it is marked, not erased.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function revoked(Connection $connection, array $data): string
    {
        $storedId = WhatsAppWebMessagePayload::storedIdOf($connection, $data['provider_message_id'] ?? null);

        if ($storedId === null || WhatsAppWebMessagePayload::externalIdOf($data['remote_jid'] ?? null) === null) {
            return 'ignored';
        }

        $message = $this->recordRevocation->handle($connection, $storedId, WhatsAppWebMessagePayload::timestamp($data['timestamp'] ?? null));

        return $message === null ? 'ignored' : 'applied';
    }

    /**
     * A slice of the history of a newly linked number: queued, never
     * processed inside the request (live events wait behind it).
     *
     * @param  array<array-key, mixed>  $data
     */
    private function historyBatch(Connection $connection, array $data): string
    {
        if (! (bool) config('whatsapp_web.history.enabled', true)) {
            return 'ignored';
        }

        $messages = array_values(array_filter(is_array($data['messages'] ?? null) ? $data['messages'] : [], 'is_array'));

        if ($messages === []) {
            return 'ignored';
        }

        foreach (array_chunk($messages, max(1, (int) config('whatsapp_web.history.job_size', 100))) as $chunk) {
            ImportWhatsAppWebHistoryBatch::dispatch($connection->id, $chunk);
        }

        return 'queued';
    }

    /**
     * The quoted message as Meta's "context" (the stored id), plus who
     * wrote it and its excerpt when WhatsApp sent them.
     *
     * @return array{id: string, from_me?: bool, preview?: string}|null
     */
    private static function context(WhatsAppWebMessagePayload $payload): ?array
    {
        $quote = $payload->quote();

        if ($quote === null) {
            return null;
        }

        $context = ['id' => $quote['message_id']];

        if (isset($quote['from_me'])) {
            $context['from_me'] = $quote['from_me'];
        }

        if (isset($quote['preview'])) {
            $context['preview'] = $quote['preview'];
        }

        return $context;
    }

    /**
     * The stored message the business replied to from the phone, among the
     * conversations of that contact on the connection.
     */
    private function repliedMessageId(Connection $connection, int $contactId, string $storedId): ?int
    {
        $id = Message::query()
            ->where('whatsapp_message_id', $storedId)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id)->where('contact_id', $contactId))
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
