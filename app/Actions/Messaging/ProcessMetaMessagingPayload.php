<?php

namespace App\Actions\Messaging;

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Connection;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaMessagingEvent;
use App\Services\Meta\MetaMessagingPayload;
use App\Services\Meta\MetaProfileLookup;
use App\Services\Meta\ProviderStatus;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Handles an authenticated Messenger or Instagram webhook body already
 * routed to its connection (StoreWebhookDelivery per account), idempotently:
 *
 * - customer messages enter the same pipeline as WhatsApp
 *   (ReceiveInboundMessage: provisional contact + identity by PSID/IGSID,
 *   conversation, routing, distribution, notifications, media download);
 *   the public name is looked up only for a sender not seen before;
 * - echoes (messages the page/account sent) are never stored again: ours
 *   are already stored by their mid; replies sent from Meta's own inbox
 *   are ignored (documented limitation);
 * - Messenger delivery/read watermarks advance every outbound message of
 *   that customer on this connection accepted by Meta before the watermark
 *   (sentUntil), and
 *   Instagram "seen" the message it names, through RecordMessageStatus
 *   (statuses only move forward; delivery_unknown is not touched by a
 *   watermark, which is not evidence about one specific message);
 * - messaging_policy_enforcement notices are recorded on the connection;
 * - entries of other accounts are ignored.
 */
class ProcessMetaMessagingPayload
{
    /** Most outbound messages a single watermark may advance. */
    public const WATERMARK_BATCH = 200;

    public function __construct(
        private ReceiveInboundMessage $receive,
        private RecordMessageStatus $statuses,
        private MetaProfileLookup $profiles,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{messages: int, duplicates: int, statuses: int, echoes: int, ignored: int}
     */
    public function handle(Connection $connection, array $payload): array
    {
        $summary = ['messages' => 0, 'duplicates' => 0, 'statuses' => 0, 'echoes' => 0, 'ignored' => 0];

        if (MetaMessagingPayload::channelOf($payload['object'] ?? null) !== $connection->channel) {
            $summary['ignored']++;

            return $summary;
        }

        foreach (MetaMessagingPayload::events($payload) as $event) {
            if ($event->accountId !== (string) $connection->external_account_id) {
                $summary['ignored']++;

                continue;
            }

            match ($event->kind) {
                MetaMessagingEvent::MESSAGE => $this->message($connection, $event, $summary),
                MetaMessagingEvent::ECHO => $this->echo($connection, $event, $summary),
                MetaMessagingEvent::DELIVERY => $summary['statuses'] += $this->receipt($connection, $event, 'delivered'),
                MetaMessagingEvent::READ => $summary['statuses'] += $this->receipt($connection, $event, 'read'),
                MetaMessagingEvent::POLICY => $this->policy($connection, $event, $summary),
                default => $summary['ignored']++,
            };
        }

        return $summary;
    }

    /**
     * @param  array{messages: int, duplicates: int, statuses: int, echoes: int, ignored: int}  $summary
     */
    private function message(Connection $connection, MetaMessagingEvent $event, array &$summary): void
    {
        /** @var InboundMessage $inbound */
        $inbound = $event->message;
        $receipt = $this->receive->handle($connection, $this->withProfileName($connection, $inbound));

        if ($receipt->outcome === InboundMessageOutcome::Duplicate) {
            $summary['duplicates']++;

            return;
        }

        $summary['messages']++;
        $this->monitor->inboundReceived($connection, $inbound->sender->occurredAt);
    }

    /**
     * @param  array{messages: int, duplicates: int, statuses: int, echoes: int, ignored: int}  $summary
     */
    private function echo(Connection $connection, MetaMessagingEvent $event, array &$summary): void
    {
        $ours = $event->mids !== [] && Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->whereIn('whatsapp_message_id', $event->mids)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
            ->exists();

        if ($ours) {
            $summary['echoes']++;

            return;
        }

        Log::info('Meta echo ignored: the message was sent outside Chat Manager.', ['connection_id' => $connection->id]);
        $summary['ignored']++;
    }

    /**
     * messaging_policy_enforcement: Meta blocked or unblocked the page's
     * messaging. The notice is kept as a provider signal and, when it blocks,
     * as the connection's last error, so administrators see it on the
     * connection's health.
     *
     * @param  array{messages: int, duplicates: int, statuses: int, echoes: int, ignored: int}  $summary
     */
    private function policy(Connection $connection, MetaMessagingEvent $event, array &$summary): void
    {
        $action = (string) $event->ignoredReason;

        Log::warning('Meta messaging policy enforcement notice.', ['connection_id' => $connection->id, 'action' => $action]);
        $this->monitor->signal($connection, 'messaging_policy_enforcement', array_filter([
            'action' => $action,
            'reason' => $event->policyReason,
        ], fn (?string $value): bool => $value !== null));

        if ($action === 'block') {
            $this->monitor->failure($connection, 'Meta bloqueó el envío de mensajes de esta página por incumplir sus políticas de mensajería. Revisa el aviso en la Bandeja de entrada de Meta Business Suite.');
        }

        $summary['ignored']++;
    }

    /**
     * Apply a delivery or read receipt; returns how many messages it reached.
     */
    private function receipt(Connection $connection, MetaMessagingEvent $event, string $status): int
    {
        $mids = $event->mids;

        if ($event->watermark !== null && $event->customerId !== null) {
            $mids = [...$mids, ...$this->sentUntil($connection, $event->customerId, $event->watermark, $status)];
        }

        $applied = 0;

        foreach (array_values(array_unique($mids)) as $mid) {
            $message = $this->statuses->handle($connection, new ProviderStatus(
                phoneNumberId: (string) $connection->external_account_id,
                messageId: $mid,
                status: $status,
                occurredAt: $event->watermark ?? $event->occurredAt,
                recipientId: $event->customerId,
            ));

            $applied += $message === null ? 0 : 1;
        }

        return $applied;
    }

    /**
     * Outbound messages to this customer through this connection that the
     * watermark certainly covers ("all messages sent before or at this
     * timestamp"). Meta answered the send (accepted_at) after it stamped the
     * message, so accepted_at is never earlier than Meta's stamp; it is kept
     * in whole seconds, hence the strict comparison with the watermark's
     * second. A message accepted in that same second (or never accepted) is
     * left for the next receipt, which lists it or carries a later
     * watermark: a missed advance is corrected later, a false "delivered" or
     * "read" never could be (statuses only move forward).
     *
     * @return list<string>
     */
    private function sentUntil(Connection $connection, string $customerId, CarbonImmutable $watermark, string $status): array
    {
        $contactIds = ContactIdentity::query()
            ->where('channel', $connection->channel)
            ->where('external_id', $customerId)
            ->pluck('contact_id');

        if ($contactIds->isEmpty()) {
            return [];
        }

        $advanceable = $status === 'read'
            ? [MessageStatus::Accepted, MessageStatus::Sent, MessageStatus::Delivered]
            : [MessageStatus::Accepted, MessageStatus::Sent];

        return array_values(Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->whereNotNull('whatsapp_message_id')
            ->whereIn('status', $advanceable)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id)->whereIn('contact_id', $contactIds))
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<', $watermark->startOfSecond())
            ->orderByDesc('id')
            ->limit(self::WATERMARK_BATCH)
            ->pluck('whatsapp_message_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->all());
    }

    /**
     * A sender seen for the first time gets the public name Meta allows to
     * read; known senders keep theirs (no call per message).
     */
    private function withProfileName(Connection $connection, InboundMessage $inbound): InboundMessage
    {
        $sender = $inbound->sender;
        $known = ContactIdentity::query()->where('channel', $sender->channel)->where('external_id', $sender->externalId)->exists();
        $name = $known ? null : $this->profiles->nameFor($connection, $sender->externalId);

        if ($name === null) {
            return $inbound;
        }

        return new InboundMessage(
            phoneNumberId: $inbound->phoneNumberId,
            sender: new InboundSender($sender->channel, $sender->provider, $sender->externalId, $name, $sender->messageId, $sender->occurredAt, $sender->referral),
            type: $inbound->type,
            body: $inbound->body,
            content: $inbound->content,
            context: $inbound->context,
        );
    }
}
