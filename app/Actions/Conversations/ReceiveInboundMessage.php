<?php

namespace App\Actions\Conversations;

use App\Actions\Contacts\RegisterInboundContact;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\ConversationRouter;
use App\Support\InboundContactRegistration;
use App\Support\InboundMessage;
use App\Support\InboundMessageReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
 * 5. distributes the conversation when its department does so automatically.
 *
 * Two messages of the same contact processed at once race on the database's
 * single-unclosed-conversation key (or deadlock on its gap locks): the loser
 * retries and joins the winner's conversation. Conversations of different connections are never mixed.
 */
class ReceiveInboundMessage
{
    /** Attempts of the transaction when MariaDB resolves a deadlock between workers. */
    private const ATTEMPTS = 3;

    public function __construct(
        private RegisterInboundContact $registerContact,
        private ConversationLifecycle $lifecycle,
        private ConversationRouter $router,
        private ReopenConversation $reopenConversation,
        private AssignAutomatically $assignAutomatically,
    ) {}

    public function handle(Connection $connection, InboundMessage $inbound): InboundMessageReceipt
    {
        $registration = $this->registerContact->handle($connection, $inbound->sender);

        // Deadlocks between workers creating the same conversation are retried
        // by the transaction itself; a unique violation means another worker
        // opened the conversation or stored this message first.
        try {
            return DB::transaction(fn (): InboundMessageReceipt => $this->receive($connection, $inbound, $registration), self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            return DB::transaction(fn (): InboundMessageReceipt => $this->receive($connection, $inbound, $registration), self::ATTEMPTS);
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
            'metadata' => array_filter([
                'content' => $inbound->content,
                'context' => $inbound->context,
                'profile_name' => $inbound->sender->profileName,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        $message->forceFill(['sent_at' => $sentAt])->save();

        $conversation->forceFill([
            'last_inbound_at' => $this->latest($conversation->last_inbound_at, $sentAt),
            'last_message_at' => $this->latest($conversation->last_message_at, $sentAt),
        ])->save();

        $this->linkReferral($registration->attribution, $conversation);

        if ($outcome === InboundMessageOutcome::Created) {
            $this->assignAutomatically->handle($conversation);
        }

        return new InboundMessageReceipt($registration, $conversation->refresh(), $message, $outcome);
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
