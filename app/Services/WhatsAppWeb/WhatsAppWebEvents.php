<?php

namespace App\Services\WhatsAppWeb;

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Actions\Messaging\RecordMessageStatus;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\Messaging\MessageRecorded;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MediaFileName;
use App\Services\Meta\ProviderStatus;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
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
 *   notifications, real time, automatic messages);
 * - message.outbound_echo: something written on the linked phone itself,
 *   stored as an outbound message of the conversation without triggering
 *   automatic messages;
 * - message.status: delivery evidence of an outbound message
 *   (RecordMessageStatus).
 *
 * Every step is idempotent: the stored message id is unique, a status only
 * moves forward and a state older than the stored one is ignored, so the
 * service may deliver the same event any number of times. Groups,
 * broadcasts, status updates and channels are never handled.
 */
class WhatsAppWebEvents
{
    /** @var list<string> */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    public function __construct(
        private WhatsAppWebSessions $sessions,
        private ReceiveInboundMessage $receive,
        private RecordMessageStatus $recordStatus,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     * @return string What was done (for the log and the tests): applied, duplicate or ignored.
     */
    public function handle(Connection $connection, string $type, array $data): string
    {
        return match ($type) {
            'session.state' => $this->sessionState($connection, $data),
            'message.inbound' => $this->inbound($connection, $data),
            'message.outbound_echo' => $this->outboundEcho($connection, $data),
            'message.status' => $this->status($connection, $data),
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

        $chat = $this->chat($data);

        if ($chat === null) {
            return 'ignored';
        }

        try {
            $inbound = new InboundMessage(
                phoneNumberId: 'wweb-'.$connection->id,
                sender: new InboundSender(
                    channel: ConnectionChannel::Whatsapp,
                    provider: ConnectionProvider::WhatsappWeb,
                    externalId: $chat['external_id'],
                    profileName: is_string($data['push_name'] ?? null) ? $data['push_name'] : null,
                    messageId: $this->storedId($connection, $data),
                    occurredAt: $this->occurredAt($data),
                ),
                type: $this->type($data),
                body: $this->body($data),
                content: $this->content($connection, $data),
                context: $this->context($connection, $data),
            );
        } catch (InvalidArgumentException $exception) {
            Log::warning('WhatsApp Web message ignored: '.$exception->getMessage(), ['connection_id' => $connection->id]);

            return 'ignored';
        }

        // A reaction that was removed carries no emoji: nothing to show.
        if ($inbound->type === MessageType::Reaction && ($inbound->body === null || $inbound->body === '')) {
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
     * A message written on the linked phone. It joins the contact's
     * conversation on this connection (the one in attention, or the most
     * recent one) as an outbound message without an author; a chat with a
     * person ChatManager never saw is not created from it.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function outboundEcho(Connection $connection, array $data): string
    {
        $chat = $this->chat($data);

        if ($chat === null) {
            return 'ignored';
        }

        try {
            $storedId = $this->storedId($connection, $data);
            $sentAt = $this->occurredAt($data);
            $type = $this->type($data);
        } catch (InvalidArgumentException) {
            return 'ignored';
        }

        if ($type === MessageType::Reaction || Message::query()->where('whatsapp_message_id', $storedId)->exists()) {
            return $type === MessageType::Reaction ? 'ignored' : 'duplicate';
        }

        $contactId = ContactIdentity::query()
            ->where('channel', ConnectionChannel::Whatsapp)
            ->where('external_id', $chat['external_id'])
            ->value('contact_id');

        if (! is_int($contactId)) {
            Log::info('WhatsApp Web message written on the phone ignored: the recipient is not a known contact.', ['connection_id' => $connection->id]);

            return 'ignored';
        }

        try {
            $stored = DB::transaction(function () use ($connection, $contactId, $data, $storedId, $sentAt, $type): ?array {
                $conversation = Conversation::query()
                    ->where('connection_id', $connection->id)
                    ->where('contact_id', $contactId)
                    ->live()
                    ->orderByRaw('closed_at is null desc')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($conversation === null) {
                    return null;
                }

                $content = $this->content($connection, $data);
                $descriptor = is_array($content['descriptor'] ?? null) ? $content['descriptor'] : null;
                unset($content['descriptor']);

                $message = new Message([
                    'conversation_id' => $conversation->id,
                    'whatsapp_message_id' => $storedId,
                    'direction' => MessageDirection::Outbound,
                    'type' => $type,
                    'status' => MessageStatus::Sent,
                    'body' => $this->body($data),
                    'reply_to_message_id' => $this->repliedMessageId($connection, $conversation, $data),
                    'metadata' => array_filter([
                        // Written on the linked phone, not in ChatManager: it has no author here.
                        'sent_from_phone' => true,
                        'content' => $content,
                    ], fn (mixed $value): bool => $value !== null),
                ]);
                $message->forceFill(['sent_at' => $sentAt, 'accepted_at' => $sentAt])->save();

                $attachment = $descriptor === null ? null : $this->pendingAttachment($message, $content, $descriptor);

                $conversation->forceFill([
                    'last_outbound_at' => $conversation->last_outbound_at === null || $sentAt->greaterThan($conversation->last_outbound_at) ? $sentAt : $conversation->last_outbound_at,
                    'last_message_at' => $conversation->last_message_at === null || $sentAt->greaterThan($conversation->last_message_at) ? $sentAt : $conversation->last_message_at,
                ])->save();

                MessageRecorded::dispatch($message->id, $conversation->id, MessageDirection::Outbound->value);

                return ['attachment' => $attachment?->id];
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        if ($stored === null) {
            Log::info('WhatsApp Web message written on the phone ignored: the contact has no conversation on this connection.', ['connection_id' => $connection->id]);

            return 'ignored';
        }

        if ($stored['attachment'] !== null) {
            DownloadWhatsAppWebMedia::dispatch($stored['attachment']);
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
            occurredAt: self::timestamp($data['timestamp'] ?? null),
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
     * The 1:1 chat of the message and the identity of the other person:
     * the digits of a phone JID, or "lid:{id}" when WhatsApp only gave a
     * privacy identifier. Groups, broadcasts, statuses and channels: null.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{external_id: string}|null
     */
    private function chat(array $data): ?array
    {
        $jid = is_string($data['remote_jid'] ?? null) ? strtolower(trim($data['remote_jid'])) : '';

        if ($jid === '' || ($data['is_group'] ?? false) === true) {
            return null;
        }

        [$user, $server] = array_pad(explode('@', $jid, 2), 2, '');
        // "5215512345678:12@s.whatsapp.net" names a device of the same account.
        $user = explode(':', $user, 2)[0];

        if ($user === '' || ! ctype_digit($user) || strlen($user) > 30) {
            return null;
        }

        return match ($server) {
            's.whatsapp.net', 'c.us' => ['external_id' => $user],
            'lid' => ['external_id' => 'lid:'.$user],
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function storedId(Connection $connection, array $data): string
    {
        $id = $data['provider_message_id'] ?? null;

        if (! is_string($id) || $id === '' || strlen($id) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
            throw new InvalidArgumentException('The message id is missing or malformed.');
        }

        return WhatsAppWebTransport::storedId($connection->id, $id);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function occurredAt(array $data): CarbonImmutable
    {
        if (! is_int($data['timestamp'] ?? null) || $data['timestamp'] <= 0) {
            throw new InvalidArgumentException('The message has no timestamp.');
        }

        return self::timestamp($data['timestamp']);
    }

    /**
     * Never in the future (a phone with a wrong clock must not push the
     * conversation ahead of everything else).
     */
    private static function timestamp(mixed $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $at = is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestampUTC($value) : $now;

        return $at->greaterThan($now) ? $now : $at;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function type(array $data): MessageType
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unsupported';

        return match ($type) {
            'text', 'image', 'video', 'audio', 'document', 'sticker', 'location', 'contacts', 'reaction' => MessageType::from($type),
            default => MessageType::Unsupported,
        };
    }

    /**
     * Readable text of the message, as MetaInboundPayload builds it.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function body(array $data): ?string
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unsupported';
        $text = is_string($data['text'] ?? null) ? $data['text'] : null;

        $body = match ($type) {
            'text', 'image', 'video', 'document' => $text,
            'reaction' => is_array($data['reaction'] ?? null) && is_string($data['reaction']['emoji'] ?? null) ? $data['reaction']['emoji'] : $text,
            'location' => $this->locationText(is_array($data['location'] ?? null) ? $data['location'] : []),
            'contacts' => $this->contactsText(is_array($data['contacts'] ?? null) ? $data['contacts'] : []),
            default => null,
        };

        if ($body === null) {
            return null;
        }

        $body = trim($body);

        return $body === '' ? null : Str::limit($body, 4096, '');
    }

    /**
     * Content in the shape the interface already renders for WhatsApp
     * Cloud API (text body, media, location, contacts, reaction). For a
     * file it carries the download descriptor, which ReceiveInboundMessage
     * moves to the attachment (encrypted) and never stores in the message.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    private function content(Connection $connection, array $data): ?array
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unsupported';

        if ($type === 'text') {
            return is_string($data['text'] ?? null) ? ['body' => $data['text']] : null;
        }

        if (in_array($type, self::MEDIA_TYPES, true)) {
            $media = is_array($data['media'] ?? null) ? $data['media'] : [];
            $descriptor = is_array($media['descriptor'] ?? null) && $media['descriptor'] !== [] ? $media['descriptor'] : null;

            return array_filter([
                'source' => 'whatsapp_web',
                'mime_type' => is_string($media['mime'] ?? null) ? $media['mime'] : null,
                'filename' => is_string($media['file_name'] ?? null) ? $media['file_name'] : null,
                'caption' => is_string($data['text'] ?? null) && $data['text'] !== '' ? $data['text'] : null,
                'voice' => ($media['voice'] ?? false) === true ? true : null,
                'seconds' => is_int($media['seconds'] ?? null) ? $media['seconds'] : null,
                'size' => is_int($media['size'] ?? null) ? $media['size'] : null,
                // Without it the file cannot be fetched: the message is kept, the file is not.
                'descriptor' => $descriptor,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($type === 'location') {
            $location = is_array($data['location'] ?? null) ? $data['location'] : [];

            return array_filter([
                'latitude' => is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
                'longitude' => is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
                'name' => is_string($location['name'] ?? null) ? Str::limit($location['name'], 255, '') : null,
                'address' => is_string($location['address'] ?? null) ? Str::limit($location['address'], 500, '') : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($type === 'contacts') {
            $contacts = [];

            foreach (array_slice(array_values(is_array($data['contacts'] ?? null) ? $data['contacts'] : []), 0, 20) as $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                $phones = array_values(array_filter((array) ($contact['phones'] ?? []), 'is_string'));
                $contacts[] = [
                    'name' => ['formatted_name' => is_string($contact['name'] ?? null) ? Str::limit($contact['name'], 255, '') : ''],
                    'phones' => array_map(fn (string $phone): array => ['phone' => Str::limit($phone, 40, '')], array_slice($phones, 0, 10)),
                ];
            }

            return ['contacts' => $contacts];
        }

        if ($type === 'reaction') {
            $reaction = is_array($data['reaction'] ?? null) ? $data['reaction'] : [];
            $target = is_string($reaction['provider_message_id'] ?? null) ? $reaction['provider_message_id']
                : (is_array($data['quoted'] ?? null) && is_string($data['quoted']['provider_message_id'] ?? null) ? $data['quoted']['provider_message_id'] : null);
            $emoji = is_string($reaction['emoji'] ?? null) ? $reaction['emoji'] : (is_string($data['text'] ?? null) ? $data['text'] : null);

            return array_filter([
                'message_id' => $target === null || $target === '' || strlen($target) > 100 ? null : WhatsAppWebTransport::storedId($connection->id, $target),
                'emoji' => $emoji,
            ], fn (mixed $value): bool => $value !== null);
        }

        return null;
    }

    /**
     * The message being replied to, as Meta's "context" (the stored id).
     *
     * @param  array<array-key, mixed>  $data
     * @return array{id: string}|null
     */
    private function context(Connection $connection, array $data): ?array
    {
        // A reaction points at its message through "reaction", not as a reply.
        if (($data['type'] ?? null) === 'reaction') {
            return null;
        }

        $quoted = is_array($data['quoted'] ?? null) ? ($data['quoted']['provider_message_id'] ?? null) : null;

        return is_string($quoted) && $quoted !== '' && strlen($quoted) <= 100
            ? ['id' => WhatsAppWebTransport::storedId($connection->id, $quoted)]
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function repliedMessageId(Connection $connection, Conversation $conversation, array $data): ?int
    {
        $context = $this->context($connection, $data);

        if ($context === null) {
            return null;
        }

        $id = Message::query()->where('whatsapp_message_id', $context['id'])->where('conversation_id', $conversation->id)->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @param  array<array-key, mixed>  $descriptor
     */
    private function pendingAttachment(Message $message, array $content, array $descriptor): MessageAttachment
    {
        $attachment = new MessageAttachment;
        $attachment->forceFill([
            'message_id' => $message->id,
            'whatsapp_media_id' => null,
            'provider_reference' => $descriptor,
            'disk' => (string) config('filesystems.media_disk', 'local'),
            'path' => null,
            'mime_type' => is_string($content['mime_type'] ?? null) ? Str::limit(trim(explode(';', $content['mime_type'])[0]), 127, '') : 'application/octet-stream',
            'file_name' => is_string($content['filename'] ?? null) ? MediaFileName::sanitize($content['filename']) : null,
            'status' => AttachmentStatus::Pending,
        ])->save();

        return $attachment;
    }

    /**
     * @param  array<array-key, mixed>  $location
     */
    private function locationText(array $location): ?string
    {
        $parts = array_filter([
            is_string($location['name'] ?? null) ? trim($location['name']) : null,
            is_string($location['address'] ?? null) ? trim($location['address']) : null,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return is_numeric($location['latitude'] ?? null) && is_numeric($location['longitude'] ?? null)
            ? $location['latitude'].', '.$location['longitude']
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $contacts
     */
    private function contactsText(array $contacts): ?string
    {
        $names = [];

        foreach ($contacts as $contact) {
            if (is_array($contact) && is_string($contact['name'] ?? null) && trim($contact['name']) !== '') {
                $names[] = trim($contact['name']);
            }
        }

        return $names === [] ? null : implode(', ', array_slice($names, 0, 10));
    }
}
