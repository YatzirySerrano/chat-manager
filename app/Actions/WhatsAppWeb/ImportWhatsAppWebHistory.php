<?php

namespace App\Actions\WhatsAppWeb;

use App\Actions\Contacts\RegisterInboundContact;
use App\Actions\Messaging\RecordMessageReaction;
use App\Enums\AttachmentStatus;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ConversationOrigin;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\DownloadWhatsAppWebMedia;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Conversations\ConversationRouter;
use App\Services\WhatsAppWeb\WhatsAppWebAttachments;
use App\Services\WhatsAppWeb\WhatsAppWebMessagePayload;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stores a slice of the chat history WhatsApp hands to a number linked by
 * QR (event "history.batch" of the WhatsApp Web service). It is a path of
 * its own, deliberately apart from ReceiveInboundMessage: history is a
 * record of what was said before ChatManager existed for that number, not
 * traffic to attend.
 *
 * - Each one-to-one chat becomes one conversation per connection and
 *   contact with origin "whatsapp_history": closed, read-only, never
 *   reopened, outside queues, SLA, metrics and the customer service window
 *   (everything that already holds for imported history, see
 *   Conversation::isImported()). It is listed with the contact's other
 *   conversations and the customer's next message starts a live one.
 * - A sender nobody knew becomes a provisional contact, exactly like the
 *   sender of a live message (RegisterInboundContact); no one is saved to
 *   the commercial directory.
 * - Messages keep their original time (sent_at and created_at), their
 *   direction (what the business wrote on the phone is outbound, without
 *   author) and the id WhatsApp gave them, stored like a live one
 *   (wweb.{connection}.{id}): a message already stored, live or imported,
 *   is skipped, so slices can be repeated, overlap or arrive in any order.
 * - Nothing is dispatched: no MessageRecorded, no notification, no
 *   automatic message or assignment, no unread counter (the conversation is
 *   closed), no response clock.
 * - A file is only fetched when the message is recent enough for WhatsApp
 *   to still serve it (config whatsapp_web.history.media_days); older ones
 *   keep the message with the attachment marked "historical_missing" and
 *   the small preview WhatsApp embedded, when there was one.
 */
class ImportWhatsAppWebHistory
{
    public function __construct(
        private RegisterInboundContact $registerContact,
        private RecordMessageReaction $recordReaction,
        private ConversationRouter $router,
    ) {}

    /**
     * @param  array<array-key, mixed>  $messages  InboundMessage payloads (contract §5).
     * @return array{imported: int, duplicates: int, skipped: int, conversations: int, downloads: int}
     */
    public function handle(Connection $connection, array $messages): array
    {
        $result = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0, 'conversations' => 0, 'downloads' => 0];
        $chats = [];

        foreach ($messages as $data) {
            if (! is_array($data)) {
                $result['skipped']++;

                continue;
            }

            $payload = new WhatsAppWebMessagePayload($connection, $data);

            $externalId = $payload->externalId();

            try {
                $entry = $externalId === null ? null : ['payload' => $payload, 'id' => $payload->storedId(), 'at' => $payload->occurredAt()];
            } catch (InvalidArgumentException) {
                $entry = null;
            }

            if ($externalId === null || $entry === null) {
                $result['skipped']++;

                continue;
            }

            $chats[$externalId][$entry['id']] = $entry;
        }

        if ($chats === []) {
            return $result;
        }

        $ids = array_merge(...array_values(array_map(fn (array $entries): array => array_map('strval', array_keys($entries)), $chats)));
        $known = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Message::query()->whereIn('whatsapp_message_id', $chunk)->pluck('whatsapp_message_id') as $id) {
                $known[(string) $id] = true;
            }
        }

        $downloads = [];

        foreach ($chats as $externalId => $entries) {
            $new = array_values(array_filter($entries, fn (array $entry): bool => ! isset($known[$entry['id']])));
            $result['duplicates'] += count($entries) - count($new);

            if ($new === []) {
                continue;
            }

            usort($new, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['id']] <=> [$b['at']->getTimestamp(), $b['id']]);

            $stored = $this->importChat($connection, (string) $externalId, $new);

            foreach (['imported', 'duplicates', 'skipped', 'conversations'] as $key) {
                $result[$key] += $stored[$key];
            }

            array_push($downloads, ...$stored['downloads']);
        }

        foreach ($downloads as $attachmentId) {
            DownloadWhatsAppWebMedia::dispatch($attachmentId);
        }

        $result['downloads'] = count($downloads);

        return $result;
    }

    /**
     * The history conversation of a contact on a connection, created
     * (closed) when it does not exist yet. Must run inside a transaction
     * that locked the contact, so two slices of the same chat processed at
     * once never create two.
     */
    public function conversationFor(Connection $connection, int $contactId, CarbonImmutable $firstAt, CarbonImmutable $lastAt): Conversation
    {
        $conversation = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contactId)
            ->where('origin', ConversationOrigin::WhatsappHistory)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation;
        $conversation->forceFill([
            'connection_id' => $connection->id,
            'contact_id' => $contactId,
            'department_id' => $this->router->route($connection)['department']?->id,
            'assigned_user_id' => null,
            'status' => ConversationStatus::Closed,
            'last_message_at' => $lastAt,
            // Never set: history opens no customer service window and answers no one.
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'first_response_at' => null,
            'closed_at' => $lastAt,
            'closed_by_user_id' => null,
            'origin' => ConversationOrigin::WhatsappHistory,
            'created_at' => $firstAt,
            'updated_at' => now(),
        ]);
        // created_at is the historical start (Eloquent keeps a dirty created_at).
        $conversation->save();

        return $conversation;
    }

    /**
     * Widens the span of a history conversation to the messages it holds.
     */
    public function extend(Conversation $conversation, CarbonImmutable $firstAt, CarbonImmutable $lastAt): void
    {
        $last = $conversation->last_message_at === null || $lastAt->greaterThan($conversation->last_message_at) ? $lastAt : $conversation->last_message_at;

        $conversation->forceFill([
            'last_message_at' => $last,
            'closed_at' => $conversation->closed_at === null || $last->greaterThan($conversation->closed_at) ? $last : $conversation->closed_at,
            'created_at' => $conversation->created_at === null || $firstAt->lessThan($conversation->created_at) ? $firstAt : $conversation->created_at,
        ])->save();
    }

    /**
     * @param  non-empty-list<array{payload: WhatsAppWebMessagePayload, id: string, at: CarbonImmutable}>  $entries  Oldest first.
     * @return array{imported: int, duplicates: int, skipped: int, conversations: int, downloads: list<int>}
     */
    private function importChat(Connection $connection, string $externalId, array $entries): array
    {
        $result = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0, 'conversations' => 0, 'downloads' => []];
        $messages = array_values(array_filter($entries, fn (array $entry): bool => $entry['payload']->type() !== MessageType::Reaction));
        $reactions = array_values(array_filter($entries, fn (array $entry): bool => $entry['payload']->type() === MessageType::Reaction));

        if ($messages !== []) {
            $first = $messages[0];
            $last = $messages[count($messages) - 1];
            $name = null;

            // The name the phone has for the chat, before the one the person chose.
            foreach ($messages as $entry) {
                $name ??= $entry['payload']->chatName();
            }

            foreach ($messages as $entry) {
                $name ??= $entry['payload']->profileName();
            }

            try {
                $registration = $this->registerContact->handle($connection, new InboundSender(
                    channel: ConnectionChannel::Whatsapp,
                    provider: ConnectionProvider::WhatsappWeb,
                    externalId: $externalId,
                    profileName: $name,
                    messageId: $last['id'],
                    occurredAt: $last['at'],
                ));
            } catch (InvalidArgumentException) {
                $result['skipped'] += count($entries);

                return $result;
            }

            $contactId = $registration->contact->id;

            $stored = DB::transaction(function () use ($connection, $contactId, $messages, $first, $last): array {
                Contact::query()->whereKey($contactId)->lockForUpdate()->value('id');

                $conversation = $this->conversationFor($connection, $contactId, $first['at'], $last['at']);
                $counts = ['imported' => 0, 'duplicates' => 0, 'conversations' => $conversation->wasRecentlyCreated ? 1 : 0, 'downloads' => []];
                $quoted = $this->quotedMessages($connection, $contactId, $messages);

                foreach ($messages as $entry) {
                    try {
                        // A savepoint per message: one already stored never undoes the rest.
                        $attachment = DB::transaction(function () use ($conversation, $entry, &$quoted): ?MessageAttachment {
                            return $this->store($conversation, $entry, $quoted);
                        });
                    } catch (UniqueConstraintViolationException) {
                        // Stored meanwhile by a live event or by another slice.
                        $counts['duplicates']++;

                        continue;
                    }

                    $counts['imported']++;

                    if ($attachment !== null && $attachment->status === AttachmentStatus::Pending) {
                        $counts['downloads'][] = $attachment->id;
                    }
                }

                if ($counts['imported'] > 0) {
                    $this->extend($conversation, $first['at'], $last['at']);
                }

                return $counts;
            });

            $result = [...$result, ...$stored];
        }

        // Reactions that travel as entries of their own join their message.
        foreach ($reactions as $entry) {
            $target = $entry['payload']->reactionTarget();
            $emoji = $entry['payload']->reactionEmoji();

            if ($target === null || $emoji === '' || $this->recordReaction->handle($connection, $target, $entry['payload']->fromMe(), $emoji, $entry['at'], signal: false) === null) {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * @param  array{payload: WhatsAppWebMessagePayload, id: string, at: CarbonImmutable}  $entry
     * @param  array<string, int>  $quoted  Stored provider id of a quotable message => its id; grows with each stored one.
     */
    private function store(Conversation $conversation, array $entry, array &$quoted): ?MessageAttachment
    {
        $payload = $entry['payload'];
        $at = $entry['at'];
        $outbound = $payload->fromMe();
        $content = $payload->content();
        $descriptor = is_array($content['descriptor'] ?? null) ? $content['descriptor'] : null;
        unset($content['descriptor']);
        $quote = $payload->quote();
        $reactions = [];

        foreach ($payload->reactions() as $reaction) {
            $reactions[$reaction['from_me'] ? RecordMessageReaction::BUSINESS : RecordMessageReaction::CUSTOMER] = ['emoji' => $reaction['emoji'], 'at' => $at->toIso8601String()];
        }

        $message = new Message([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $entry['id'],
            'direction' => $outbound ? MessageDirection::Outbound : MessageDirection::Inbound,
            'type' => $payload->type(),
            'status' => match (true) {
                ! $outbound => MessageStatus::Received,
                $payload->deliveryStatus() === 'read' => MessageStatus::Read,
                $payload->deliveryStatus() === 'delivered' => MessageStatus::Delivered,
                default => MessageStatus::Sent,
            },
            'body' => $payload->body(),
            'reply_to_message_id' => $quote === null ? null : ($quoted[$quote['message_id']] ?? null),
            'metadata' => array_filter([
                'content' => $content === [] ? null : $content,
                'context' => $payload->context(),
                'reply_to' => $quote,
                'profile_name' => $outbound ? null : $payload->profileName(),
                // Written on the phone (or before ChatManager): it has no author here.
                'sent_from_phone' => $outbound ? true : null,
                'reactions' => $reactions === [] ? null : $reactions,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $message->forceFill([
            'sent_at' => $at,
            'accepted_at' => $outbound ? $at : null,
            'revoked_at' => $payload->revoked() ? $at : null,
            'is_historical' => true,
            // The time it was written, so the timeline shows it where it belongs.
            'created_at' => $at,
            'updated_at' => now(),
        ])->save();

        $quoted[$entry['id']] = $message->id;

        if (! in_array($payload->rawType(), WhatsAppWebMessagePayload::MEDIA_TYPES, true)) {
            return null;
        }

        $fetchable = $descriptor !== null && $at->greaterThanOrEqualTo(CarbonImmutable::now()->subDays(max(0, (int) config('whatsapp_web.history.media_days', 30))));

        return WhatsAppWebAttachments::create($message, $content ?? [], $fetchable ? $descriptor : null, 'historical_missing');
    }

    /**
     * Messages quoted by the slice that are already stored for the contact
     * on the connection, in one query.
     *
     * @param  list<array{payload: WhatsAppWebMessagePayload, id: string, at: CarbonImmutable}>  $entries
     * @return array<string, int>
     */
    private function quotedMessages(Connection $connection, int $contactId, array $entries): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $entry): ?string => $entry['payload']->context()['id'] ?? null, $entries))));

        if ($ids === []) {
            return [];
        }

        return Message::query()
            ->whereIn('whatsapp_message_id', $ids)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id)->where('contact_id', $contactId))
            ->pluck('id', 'whatsapp_message_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
