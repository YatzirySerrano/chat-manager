<?php

namespace App\Actions\Contacts;

use App\Enums\AttributionReliability;
use App\Enums\AuditAction;
use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ContactOriginType;
use App\Enums\ContactReviewReason;
use App\Enums\ContactStatus;
use App\Enums\InboundContactOutcome;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\ContactIdentity;
use App\Models\ContactReviewCase;
use App\Services\AuditLogger;
use App\Support\InboundContactRegistration;
use App\Support\InboundSender;
use App\Support\PhoneNumber;
use App\Support\ProviderReferral;
use App\Support\ReadCommittedTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registers the sender of an inbound message, without a web session or any
 * human action. A sender nobody knew becomes a provisional contact (saved_at
 * null): a technical record that keeps the identity, the conversations and
 * replies working, but stays out of the commercial directory, its reports and
 * tag counts until someone saves it (SaveContactToDirectory). Contacts that
 * were already saved keep being saved.
 *
 * 1. The provider identity (channel + external id, unique in the database) is
 *    looked up; when known, its contact is reused.
 * 2. Otherwise, for WhatsApp only, the phone the provider verified is
 *    compared with contacts registered by hand. Only a single candidate without a WhatsApp identity
 *    is linked; any other match registers a new contact and opens a review
 *    case, so people are never merged on ambiguous evidence.
 * 3. The interaction is recorded (first/last interaction of the contact and of
 *    the connection) and the provider's referral is kept as confirmed origin.
 *
 * Visibility rules are not applied here: the directory is global and they
 * restrict what each employee can read, not which identity a sender has.
 *
 * Every step is idempotent and safe under concurrency: two workers handling
 * the same sender race on the identity's unique key, and the loser reuses the
 * winner's contact; a repeated message never records its referral twice;
 * events processed out of order never move interaction dates backwards nor
 * restore an older profile name. Names, notes and tags edited by employees
 * are never overwritten.
 */
class RegisterInboundContact
{
    public function __construct(
        private AuditLogger $audit,
        private OpenContactReview $openReview,
        private ReadCommittedTransaction $transaction,
    ) {}

    public function handle(Connection $connection, InboundSender $sender): InboundContactRegistration
    {
        [$identity, $outcome, $reviewCase] = $this->resolveIdentity($connection, $sender);

        return $this->transaction->run('inbound.contact.interaction', function () use ($connection, $sender, $identity, $outcome, $reviewCase): InboundContactRegistration {
            $contact = Contact::query()->lockForUpdate()->findOrFail($identity->contact_id);
            $identity = ContactIdentity::query()->lockForUpdate()->findOrFail($identity->id);

            $this->recordInteraction($connection, $contact, $identity, $sender);

            $attribution = $sender->referral === null
                ? null
                : $this->recordReferral($contact, $sender->messageId, $sender->referral, $sender->occurredAt, $sender->provider);

            return new InboundContactRegistration($contact, $identity, $connection, $outcome, $attribution, $reviewCase);
        });
    }

    /**
     * @return array{ContactIdentity, InboundContactOutcome, ContactReviewCase|null}
     */
    private function resolveIdentity(Connection $connection, InboundSender $sender): array
    {
        $known = $this->findIdentity($sender);

        if ($known !== null) {
            return [$known, InboundContactOutcome::Matched, null];
        }

        try {
            // At READ COMMITTED the phone lookup of a new sender takes no gap
            // lock, so different new senders never block each other; the
            // same sender registered by two workers races on the identity's
            // unique key.
            return $this->transaction->run('inbound.contact.identity', fn (): array => $this->registerIdentity($connection, $sender));
        } catch (UniqueConstraintViolationException) {
            // Another worker registered the same sender first: use its contact.
            return [$this->findIdentity($sender) ?? throw new \LogicException('The concurrently registered identity was not found.'), InboundContactOutcome::Matched, null];
        }
    }

    private function findIdentity(InboundSender $sender): ?ContactIdentity
    {
        return ContactIdentity::query()
            ->where('channel', $sender->channel)
            ->where('external_id', $sender->externalId)
            ->first();
    }

    /**
     * @return array{ContactIdentity, InboundContactOutcome, ContactReviewCase|null}
     */
    private function registerIdentity(Connection $connection, InboundSender $sender): array
    {
        // Only a WhatsApp id is a phone the provider verified; ids of other
        // channels (web chat visitors: 'wv:…' / 'wi:…') never enter the
        // phone matching, so people are never linked through them.
        $phone = $sender->channel === ConnectionChannel::Whatsapp && ctype_digit($sender->externalId)
            ? PhoneNumber::normalize('+'.$sender->externalId)
            : null;

        $candidates = $phone === null ? new EloquentCollection : Contact::query()
            ->where('phone_e164', $phone)
            ->withCount(['identities' => fn (Builder $identities) => $identities->where('channel', $sender->channel)])
            ->orderBy('id')
            ->limit(20)
            ->lockForUpdate()
            ->get(['id']);

        $single = $candidates->count() === 1 ? $candidates->first() : null;

        if ($single !== null && $single->identities_count === 0) {
            $identity = $this->createIdentity($single, $sender);

            $this->audit->record(AuditAction::ContactIdentityLinked, $single, [
                'channel' => $sender->channel->value,
                'connection_id' => $connection->id,
                'matched_by' => 'phone',
            ]);

            return [$identity, InboundContactOutcome::Linked, null];
        }

        $contact = new Contact([
            'name' => $sender->profileName !== null ? mb_substr($sender->profileName, 0, 160) : ($phone ?? $sender->channel->fallbackContactName($sender->externalId)),
            'phone_e164' => $phone,
            'profile_name' => $sender->profileName,
        ]);
        $contact->forceFill([
            'status' => ContactStatus::Active,
            'source' => $sender->channel->contactSource(),
            'created_by_user_id' => null,
            'saved_at' => null,
            'saved_by_user_id' => null,
            'first_interaction_at' => $sender->occurredAt,
            'last_interaction_at' => $sender->occurredAt,
        ])->save();

        $identity = $this->createIdentity($contact, $sender);

        $this->audit->record(AuditAction::ContactCreated, $contact, [
            'source' => $sender->channel->contactSource()->value,
            'automatic' => true,
            'provisional' => true,
            'connection_id' => $connection->id,
            'has_phone' => $phone !== null,
        ]);

        $reviewCase = $candidates->isEmpty() ? null : $this->openReview->handle(
            $contact,
            ContactReviewReason::AmbiguousIdentityMatch,
            $candidates->modelKeys(),
            ['matched_by' => 'phone', 'channel' => $sender->channel->value, 'connection_id' => $connection->id],
        );

        return [$identity, InboundContactOutcome::Created, $reviewCase];
    }

    private function createIdentity(Contact $contact, InboundSender $sender): ContactIdentity
    {
        return ContactIdentity::create([
            'contact_id' => $contact->id,
            'channel' => $sender->channel,
            'provider' => $sender->provider,
            'external_id' => $sender->externalId,
            'display_name' => $sender->profileName,
        ]);
    }

    /**
     * Interaction dates only move outwards (earliest first, latest last), and
     * the profile name is refreshed only by the newest event, so late or
     * repeated deliveries change nothing.
     */
    private function recordInteraction(Connection $connection, Contact $contact, ContactIdentity $identity, InboundSender $sender): void
    {
        $at = $sender->occurredAt;
        $isNewest = $identity->last_seen_at === null || $at->greaterThanOrEqualTo($identity->last_seen_at);

        if ($isNewest) {
            $identity->forceFill([
                'last_seen_at' => $at,
                'display_name' => $sender->profileName ?? $identity->display_name,
            ])->save();

            if ($sender->profileName !== null) {
                $contact->profile_name = $sender->profileName;
            }
        }

        $contact->forceFill([
            'first_interaction_at' => $this->earliest($contact->first_interaction_at, $at),
            'last_interaction_at' => $this->latest($contact->last_interaction_at, $at),
        ])->save();

        $pivot = DB::table('connection_contact')
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->first(['id', 'first_interaction_at', 'last_interaction_at']);

        if ($pivot === null) {
            DB::table('connection_contact')->insert([
                'connection_id' => $connection->id,
                'contact_id' => $contact->id,
                'first_interaction_at' => $at,
                'last_interaction_at' => $at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('connection_contact')->where('id', $pivot->id)->update([
            'first_interaction_at' => $this->earliest($pivot->first_interaction_at === null ? null : CarbonImmutable::parse($pivot->first_interaction_at), $at),
            'last_interaction_at' => $this->latest($pivot->last_interaction_at === null ? null : CarbonImmutable::parse($pivot->last_interaction_at), $at),
            'updated_at' => now(),
        ]);
    }

    /**
     * Keep the referral as provider confirmed evidence, once per message. It
     * never replaces other attributions: the origin shown is resolved from
     * all of them (see ResolveContactOrigin).
     */
    private function recordReferral(Contact $contact, string $messageId, ProviderReferral $referral, CarbonImmutable $at, ConnectionProvider $provider): ContactAttribution
    {
        $existing = ContactAttribution::query()->where('provider_message_id', $messageId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $contact->attributions()->create([
            'provider_message_id' => $messageId,
            'origin_type' => match ($referral->sourceType) {
                'ad' => ContactOriginType::MetaAd,
                'post' => ContactOriginType::MetaPost,
                default => ContactOriginType::Other,
            },
            'reliability' => AttributionReliability::ProviderConfirmed,
            // Meta's referrals keep their historical provider name ('meta').
            'provider' => $provider === ConnectionProvider::MetaCloudApi ? 'meta' : $provider->value,
            'source_id' => $referral->sourceId,
            'source_type' => $referral->sourceType,
            'source_url' => $referral->sourceUrl,
            'headline' => $referral->headline,
            'body' => $referral->body,
            'ctwa_clid' => $referral->ctwaClid,
            'media_type' => $referral->mediaType,
            'raw_referral' => $referral->raw,
            'captured_at' => $at,
        ]);
    }

    private function earliest(?\DateTimeInterface $current, CarbonImmutable $candidate): \DateTimeInterface
    {
        return $current === null || $candidate->lessThan($current) ? $candidate : $current;
    }

    private function latest(?\DateTimeInterface $current, CarbonImmutable $candidate): \DateTimeInterface
    {
        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }
}
