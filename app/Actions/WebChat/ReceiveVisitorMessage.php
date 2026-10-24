<?php

namespace App\Actions\WebChat;

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageType;
use App\Exceptions\ConversationConflict;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WebChatSession;
use App\Services\Meta\ConnectionMonitor;
use App\Services\WebChat\VisitorSessions;
use App\Support\InboundMessage;
use App\Support\InboundSender;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 */
class ReceiveVisitorMessage
{
    public function __construct(
        private ReceiveInboundMessage $receiveInbound,
        private ConnectionMonitor $monitor,
        private VisitorSessions $sessions,
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
            messageId: 'web.in.'.$session->public_id.'.'.strtolower($clientMessageId),
            occurredAt: $now,
        );

        $receipt = $this->receiveInbound->handle($connection, new InboundMessage('', $sender, MessageType::Text, $body));
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
