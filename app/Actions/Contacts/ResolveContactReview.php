<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactReviewReason;
use App\Enums\ContactReviewResolution;
use App\Enums\ContactReviewStatus;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a review case with the administrator's decision. Nothing is removed:
 * a duplicate is logically deleted (archived) and keeps its history, and
 * every move is audited.
 */
class ResolveContactReview
{
    public function __construct(
        private AuditLogger $audit,
        private DeleteContact $deleteContact,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(ContactReviewCase $case, ContactReviewResolution $resolution, ?int $targetContactId, User $actor): ContactReviewCase
    {
        return DB::transaction(function () use ($case, $resolution, $targetContactId, $actor): ContactReviewCase {
            $case = ContactReviewCase::query()->lockForUpdate()->findOrFail($case->id);

            if (! $case->isOpen()) {
                throw ValidationException::withMessages(['resolution' => 'Este caso ya fue resuelto.']);
            }

            $target = $resolution === ContactReviewResolution::DistinctPeople
                ? null
                : $this->target($case, $targetContactId);

            match ($resolution) {
                ContactReviewResolution::DistinctPeople => null,
                ContactReviewResolution::IdentityLinked => $this->linkIdentity($case, $target, $actor),
                ContactReviewResolution::DuplicateArchived => $this->archiveDuplicate($case, $actor),
            };

            $case->forceFill([
                'status' => ContactReviewStatus::Resolved,
                'resolution' => $resolution,
                'resolved_contact_id' => $target?->id,
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            $this->audit->record(AuditAction::ContactReviewResolved, $case->contact()->firstOrFail(), [
                'review_case_id' => $case->id,
                'reason' => $case->reason->value,
                'resolution' => $resolution->value,
                'resolved_contact_id' => $target?->id,
            ], $actor);

            return $case;
        });
    }

    /**
     * @throws ValidationException
     */
    private function target(ContactReviewCase $case, ?int $targetContactId): Contact
    {
        if ($targetContactId === null || ! in_array($targetContactId, $case->candidate_contact_ids, true)) {
            throw ValidationException::withMessages(['target_contact_id' => 'Elige uno de los contactos coincidentes.']);
        }

        return Contact::query()->findOrFail($targetContactId);
    }

    /**
     * Move the WhatsApp identity of an automatically registered contact, with
     * its connections, provider evidence, notes and tags, to the existing
     * contact; the automatic one is archived. Conversations cannot be moved
     * yet, so a contact that already has some is refused.
     *
     * @throws ValidationException
     */
    private function linkIdentity(ContactReviewCase $case, Contact $target, User $actor): void
    {
        if ($case->reason !== ContactReviewReason::AmbiguousIdentityMatch) {
            throw ValidationException::withMessages(['resolution' => 'Solo se puede vincular WhatsApp cuando el caso proviene de un remitente de WhatsApp.']);
        }

        [$firstId, $secondId] = $case->contact_id < $target->id ? [$case->contact_id, $target->id] : [$target->id, $case->contact_id];
        $locked = Contact::query()->whereKey([$firstId, $secondId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $locked->get($case->contact_id) ?? throw new \LogicException('The contact under review was not found.');
        $target = $locked->get($target->id) ?? throw new \LogicException('The chosen contact was not found.');

        if ($source->conversations()->exists()) {
            throw ValidationException::withMessages(['resolution' => 'Este contacto ya tiene conversaciones; todavía no es posible trasladarlas. Márcalo como persona distinta o archiva el duplicado más adelante.']);
        }

        $source->identities()->update(['contact_id' => $target->id]);
        $source->attributions()->update(['contact_id' => $target->id]);
        $source->notes()->getQuery()->update(['contact_id' => $target->id]);

        // Tags keep who applied them and when; a tag both contacts carry stays once.
        DB::table('contact_tag')->insertOrIgnore(DB::table('contact_tag')
            ->where('contact_id', $source->id)
            ->get(['tag_id', 'tagged_by_user_id', 'created_at'])
            ->map(fn (object $row): array => [
                'contact_id' => $target->id,
                'tag_id' => $row->tag_id,
                'tagged_by_user_id' => $row->tagged_by_user_id,
                'created_at' => $row->created_at,
            ])
            ->all());
        DB::table('contact_tag')->where('contact_id', $source->id)->delete();
        $this->moveConnections($source, $target);

        $target->forceFill([
            'profile_name' => $source->profile_name ?? $target->profile_name,
            'phone_e164' => $target->phone_e164 ?? $source->phone_e164,
            'first_interaction_at' => $this->earliest($target->first_interaction_at, $source->first_interaction_at),
            'last_interaction_at' => $this->latest($target->last_interaction_at, $source->last_interaction_at),
        ])->save();

        $this->audit->record(AuditAction::ContactIdentityLinked, $target, [
            'from_contact_id' => $source->id,
            'review_case_id' => $case->id,
            'matched_by' => 'administrator',
        ], $actor);

        $this->deleteContact->handle($source, $actor);
    }

    private function moveConnections(Contact $source, Contact $target): void
    {
        foreach (DB::table('connection_contact')->where('contact_id', $source->id)->get() as $row) {
            $existing = DB::table('connection_contact')
                ->where('contact_id', $target->id)
                ->where('connection_id', $row->connection_id)
                ->first();

            if ($existing === null) {
                DB::table('connection_contact')->where('id', $row->id)->update(['contact_id' => $target->id, 'updated_at' => now()]);

                continue;
            }

            DB::table('connection_contact')->where('id', $existing->id)->update([
                'first_interaction_at' => $this->earliest($this->date($existing->first_interaction_at), $this->date($row->first_interaction_at)),
                'last_interaction_at' => $this->latest($this->date($existing->last_interaction_at), $this->date($row->last_interaction_at)),
                'updated_at' => now(),
            ]);
            DB::table('connection_contact')->where('id', $row->id)->delete();
        }
    }

    private function archiveDuplicate(ContactReviewCase $case, User $actor): void
    {
        if ($case->reason !== ContactReviewReason::PossibleDuplicate) {
            throw ValidationException::withMessages(['resolution' => 'Un remitente de WhatsApp se resuelve vinculándolo o marcándolo como persona distinta.']);
        }

        $this->deleteContact->handle($case->contact()->firstOrFail(), $actor);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    private function earliest(?\DateTimeInterface $first, ?\DateTimeInterface $second): ?\DateTimeInterface
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return $first < $second ? $first : $second;
    }

    private function latest(?\DateTimeInterface $first, ?\DateTimeInterface $second): ?\DateTimeInterface
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return $first > $second ? $first : $second;
    }
}
