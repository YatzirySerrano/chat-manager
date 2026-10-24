<?php

namespace App\Services\WebChat;

use App\Enums\ConnectionChannel;
use App\Enums\MessageDirection;
use App\Http\Resources\WebChat\VisitorMessageResource;
use App\Models\ContactIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebChatSession;
use App\Models\WebChatWidget;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifecycle of the web chat visitor sessions:
 *
 * - start() opens a session when the widget loads, bound to the host origin
 *   its frame ticket proved. No contact or conversation is created: they
 *   only exist once the visitor writes (ReceiveVisitorMessage). An
 *   anonymous visitor gets a fresh 'wv:' identity per session; an
 *   identified one gets the 'wi:' identity of the connection, so their
 *   history follows them across devices.
 * - identify() turns a session into an identified one. A session that has
 *   not written yet simply changes identity; one that already wrote is
 *   revoked ('identified') and replaced, so an anonymous history is never
 *   attached to a person. It runs under a row lock of the session.
 * - attachIdentifiedContact() hands a newly created contact to the
 *   person's other identified sessions on the connection.
 * - revoke() ends a session immediately (its token stops working).
 * - visibleMessages() is the only query of what a session may read: the
 *   messages of its contact's conversations on its connection, inbound in
 *   any state and outbound only once they left (accepted onwards).
 *
 * Tokens are only returned here, in plain text, once; the database keeps
 * their hash. The IP is only stored as an HMAC.
 */
class VisitorSessions
{
    /**
     * @param  array{name?: string|null, email?: string|null}|null  $profile
     * @return array{session: WebChatSession, token: string}
     */
    public function start(WebChatWidget $widget, string $hostOrigin, ?string $ip, ?string $userAgent, ?array $profile, ?VerifiedIdentity $identity, ?string $landingUrl): array
    {
        $session = new WebChatSession([
            'connection_id' => $widget->connection_id,
            'origin' => Str::limit($hostOrigin, 255, ''),
            'landing_url' => $landingUrl === null ? null : Str::limit($landingUrl, 2048, ''),
            'ip_hash' => WebChatSession::hashIp($ip),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 255, ''),
            'last_seen_at' => now(),
            'expires_at' => $this->initialExpiry($widget),
        ]);

        $this->applyIdentity($session, $identity, $profile);

        $token = $session->generateToken();
        $session->save();

        return ['session' => $session, 'token' => $token];
    }

    /**
     * @return array{session: WebChatSession, token: string|null}
     */
    public function identify(WebChatSession $session, VerifiedIdentity $identity): array
    {
        // Serialized on the session row: a send racing with this call (or a
        // second identify) must never see, or leave, a half-changed session.
        // The decision uses the fresh contact_id and revoked_at, not the copy
        // the middleware read before the lock.
        return DB::transaction(function () use ($session, $identity): array {
            $fresh = WebChatSession::query()->lockForUpdate()->find($session->id);

            if ($fresh === null || ! $fresh->isUsable()) {
                throw new AuthenticationException;
            }

            $fresh->setRelation('connection', $session->connection);

            if (($fresh->identified && $fresh->visitor_id === $identity->visitorId) || $fresh->contact_id === null) {
                $this->applyIdentity($fresh, $identity, null);
                $fresh->save();

                return ['session' => $fresh, 'token' => null];
            }

            $this->revoke($fresh, 'identified');

            /** @var WebChatWidget $widget */
            $widget = $session->connection->webChatWidget;

            $replacement = $this->start($widget, $fresh->origin, null, $fresh->user_agent, null, $identity, $fresh->landing_url);
            $replacement['session']->forceFill(['ip_hash' => $fresh->ip_hash])->save();

            return $replacement;
        });
    }

    /**
     * The contact of an identified person now exists on the connection: the
     * person's other identified sessions (other devices opened before the
     * contact existed) read its history and get its signals from now on.
     */
    public function attachIdentifiedContact(int $connectionId, string $visitorId, int $contactId): void
    {
        WebChatSession::query()
            ->where('connection_id', $connectionId)
            ->where('visitor_id', $visitorId)
            ->where('identified', true)
            ->whereNull('contact_id')
            ->whereNull('revoked_at')
            ->update(['contact_id' => $contactId]);
    }

    /**
     * Messages the session may read (none until the visitor wrote or, when
     * identified, until their contact exists on this connection).
     *
     * @return Builder<Message>
     */
    public function visibleMessages(WebChatSession $session): Builder
    {
        return Message::query()
            ->whereIn('conversation_id', Conversation::query()
                ->select('id')
                ->where('connection_id', $session->connection_id)
                ->where('contact_id', $session->contact_id ?? 0))
            ->where(fn (Builder $visible) => $visible
                ->where('direction', MessageDirection::Inbound)
                ->orWhere(fn (Builder $outbound) => $outbound
                    ->where('direction', MessageDirection::Outbound)
                    ->whereIn('status', VisitorMessageResource::VISIBLE_OUTBOUND)));
    }

    public function revoke(WebChatSession $session, string $reason): void
    {
        $session->forceFill([
            'revoked_at' => now(),
            'revoke_reason' => Str::limit($reason, 40, ''),
        ])->save();
    }

    /**
     * @param  array{name?: string|null, email?: string|null}|null  $profile
     */
    private function applyIdentity(WebChatSession $session, ?VerifiedIdentity $identity, ?array $profile): void
    {
        if ($identity === null) {
            $session->forceFill([
                'visitor_id' => 'wv:'.strtolower((string) Str::ulid()),
                'identified' => false,
                'contact_id' => null,
                'profile' => $this->profile($profile['name'] ?? null, $profile['email'] ?? null),
            ]);

            return;
        }

        // The person may already be a contact of this connection (another
        // device or an earlier session): the session reads their history.
        $contactId = ContactIdentity::query()
            ->where('channel', ConnectionChannel::WebChat)
            ->where('external_id', $identity->visitorId)
            ->value('contact_id');

        $session->forceFill([
            'visitor_id' => $identity->visitorId,
            'identified' => true,
            'contact_id' => is_int($contactId) ? $contactId : null,
            'profile' => $this->profile($identity->name, $identity->email),
        ]);
    }

    /**
     * @return array{name: string|null, email: string|null}|null
     */
    private function profile(?string $name, ?string $email): ?array
    {
        $name = $name === null ? null : Str::limit(Str::squish(strip_tags($name)), 80, '');
        $email = $email === null ? null : Str::limit(trim($email), 191, '');
        $name = $name === '' ? null : $name;
        $email = $email === '' ? null : $email;

        return $name === null && $email === null ? null : ['name' => $name, 'email' => $email];
    }

    private function initialExpiry(WebChatWidget $widget): \DateTimeInterface
    {
        $idle = now()->addDays(max(1, $widget->session_idle_days));
        $absolute = now()->addDays((int) config('webchat.session.absolute_days', 90));

        return $idle->lessThan($absolute) ? $idle : $absolute;
    }
}
