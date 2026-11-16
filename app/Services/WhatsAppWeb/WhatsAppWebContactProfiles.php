<?php

namespace App\Services\WhatsAppWeb;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\WhatsAppWebState;
use App\Jobs\RefreshWhatsAppWebContactProfile;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Conversation;

/**
 * The WhatsApp profile of a contact (picture, "about", business profile),
 * as WhatsApp shares it through a WhatsApp Web (QR) session. WhatsApp Cloud
 * API never shares the picture or the "about" of a customer, only the
 * profile name, so for those contacts there is nothing to show.
 *
 * - Refreshed in the background after an inbound message of a WhatsApp Web
 *   connection when it was never read or is older than a day, and on demand
 *   («Actualizar», throttled).
 * - The picture is a copy on the private media disk, served only to people
 *   who can see the contact or one of its conversations
 *   (ContactAvatarController); WhatsApp's CDN is never linked from the
 *   browser (its URLs expire and would reveal the viewer's IP to WhatsApp).
 */
class WhatsAppWebContactProfiles
{
    /** A stored profile older than this is read again after a new message. */
    public const STALE_AFTER_HOURS = 24;

    /**
     * Queues a refresh of the identity behind an inbound message of a
     * WhatsApp Web connection, when its profile is missing or stale.
     */
    public function refreshAfterInbound(Connection $connection, string $externalId): void
    {
        if (! $connection->isWhatsAppWeb()) {
            return;
        }

        $identity = ContactIdentity::query()
            ->where('channel', ConnectionChannel::Whatsapp)
            ->where('external_id', $externalId)
            ->first(['id', 'profile_fetched_at']);

        if ($identity !== null && self::isStale($identity)) {
            RefreshWhatsAppWebContactProfile::dispatch($identity->id, $connection->id);
        }
    }

    public static function isStale(ContactIdentity $identity): bool
    {
        return $identity->profile_fetched_at === null
            || $identity->profile_fetched_at->lessThan(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /**
     * Who the service asks about: the chat's own JID when WhatsApp Web
     * already saw this person (or "…@lid" when WhatsApp never gave a
     * phone), otherwise the digits, for which the service looks up the JID.
     */
    public static function recipient(ContactIdentity $identity): ?string
    {
        $id = $identity->external_id;

        if (str_starts_with($id, 'lid:') && ctype_digit(substr($id, 4))) {
            return substr($id, 4).'@lid';
        }

        if (! ctype_digit($id) || strlen($id) < 7 || strlen($id) > 15) {
            return null;
        }

        return $identity->provider === ConnectionProvider::WhatsappWeb ? $id.'@s.whatsapp.net' : $id;
    }

    /**
     * The WhatsApp Web connection to read the contact's profile through:
     * the given conversation's own connection when it is one, otherwise the
     * one the contact wrote to most recently. Connected sessions first.
     */
    public function connectionFor(Contact $contact, ?Conversation $conversation = null): ?Connection
    {
        $own = $conversation?->channelConnection;

        if ($own instanceof Connection && $own->isWhatsAppWeb() && ! $own->isDeleted()) {
            return $own;
        }

        return $contact->connections()
            ->where('connections.provider', ConnectionProvider::WhatsappWeb->value)
            ->whereNull('connections.deleted_at')
            ->orderByRaw('connections.web_session_state = ? desc', [WhatsAppWebState::Connected->value])
            ->orderByDesc('connection_contact.last_interaction_at')
            ->first(['connections.*']);
    }

    /**
     * The «Perfil de WhatsApp» section of the details panel and the contact
     * page, or null when the contact has no WhatsApp identity.
     *
     * `source`: "whatsapp_web" when a WhatsApp Web connection can read the
     * profile, "cloud_api" when the contact only talks through WhatsApp
     * Cloud API (which shares neither picture nor info).
     *
     * @param  string  $avatarUrl  URL of the stored picture (or null) for this viewer.
     * @return array<string, mixed>|null
     */
    public function present(Contact $contact, ?Connection $connection, ?string $avatarUrl, bool $canRefresh, string $refreshUrl): ?array
    {
        $identity = $contact->relationLoaded('whatsappIdentity')
            ? $contact->getRelation('whatsappIdentity')
            : $contact->whatsappIdentity()->first();

        if (! $identity instanceof ContactIdentity) {
            return null;
        }

        $web = $connection !== null && $connection->isWhatsAppWeb();
        $business = is_array($identity->business_profile) ? $identity->business_profile : null;

        return [
            'source' => $web ? 'whatsapp_web' : 'cloud_api',
            'avatar_url' => $avatarUrl,
            'about' => $identity->profile_about,
            'about_set_at' => $identity->profile_about_set_at?->toIso8601String(),
            'business' => $business === null ? null : [
                'category' => self::text($business['category'] ?? null),
                'description' => self::text($business['description'] ?? null),
                'email' => self::text($business['email'] ?? null),
                // Only web addresses: anything else is never turned into a link.
                'website' => array_values(array_filter(
                    array_map(self::text(...), is_array($business['website'] ?? null) ? $business['website'] : []),
                    fn (?string $site): bool => $site !== null && preg_match('#^https?://#i', $site) === 1,
                )),
                'address' => self::text($business['address'] ?? null),
                'hours' => self::hours($business['hours'] ?? null),
            ],
            'fetched_at' => $identity->profile_fetched_at?->toIso8601String(),
            'can_refresh' => $web && $canRefresh,
            'refresh_url' => $web && $canRefresh ? $refreshUrl : null,
        ];
    }

    /**
     * URL of the stored picture through the conversation (inbox rows, open
     * conversation, details panel), or null when there is none.
     */
    public static function conversationAvatarUrl(Conversation $conversation): ?string
    {
        $contact = $conversation->relationLoaded('contact') ? $conversation->contact : null;
        $identity = $contact?->relationLoaded('whatsappIdentity') ? $contact->getRelation('whatsappIdentity') : null;

        return $identity instanceof ContactIdentity && $identity->hasAvatar()
            ? route('conversations.contact-avatar', ['conversation' => $conversation->id, 'v' => $identity->avatarVersion()])
            : null;
    }

    /**
     * URL of the stored picture through the contact (contact page).
     */
    public static function contactAvatarUrl(Contact $contact): ?string
    {
        $identity = $contact->relationLoaded('whatsappIdentity') ? $contact->getRelation('whatsappIdentity') : null;

        return $identity instanceof ContactIdentity && $identity->hasAvatar()
            ? route('contacts.avatar', ['contact' => $contact->id, 'v' => $identity->avatarVersion()])
            : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Business hours as the panel lists them, Monday first.
     *
     * @return array{timezone: string|null, days: list<array{day: string, label: string, hours: string}>}|null
     */
    private static function hours(mixed $hours): ?array
    {
        if (! is_array($hours) || ! is_array($hours['days'] ?? null)) {
            return null;
        }

        $labels = ['mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo'];
        $days = [];

        foreach ($hours['days'] as $day) {
            $key = is_array($day) && is_string($day['day'] ?? null) ? strtolower($day['day']) : null;

            if ($key === null || ! isset($labels[$key])) {
                continue;
            }

            $open = is_int($day['open'] ?? null) ? $day['open'] : null;
            $close = is_int($day['close'] ?? null) ? $day['close'] : null;

            $text = match ($day['mode'] ?? null) {
                'open_24h' => 'Abierto 24 horas',
                'appointment_only' => 'Solo con cita',
                default => $open !== null && $close !== null ? self::clock($open).' – '.self::clock($close) : 'Abierto',
            };

            // Several ranges of the same day are joined.
            $days[$key] = isset($days[$key]) ? $days[$key].', '.$text : $text;
        }

        if ($days === []) {
            return null;
        }

        $ordered = [];

        foreach ($labels as $key => $label) {
            $ordered[] = ['day' => $key, 'label' => $label, 'hours' => $days[$key] ?? 'Cerrado'];
        }

        return ['timezone' => self::text($hours['timezone'] ?? null), 'days' => $ordered];
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
