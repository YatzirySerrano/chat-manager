<?php

namespace App\Actions\Contacts;

use App\Enums\AuditAction;
use App\Enums\ContactReviewReason;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;

class OpenContactReview
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Open a review case for the contact, or add the candidates to the case
     * already open for the same reason (a single open case per contact and
     * reason is enforced by a unique key).
     *
     * @param  array<array-key, int|string>  $candidateIds  Normalized to a sorted list of unique ids.
     * @param  array<string, mixed>  $evidence
     */
    public function handle(Contact $contact, ContactReviewReason $reason, array $candidateIds, array $evidence = []): ContactReviewCase
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($candidateIds);

        $existing = $this->openCase($contact, $reason);

        if ($existing === null) {
            try {
                $case = ContactReviewCase::create([
                    'contact_id' => $contact->id,
                    'reason' => $reason,
                    'candidate_contact_ids' => $candidateIds,
                    'evidence' => $evidence === [] ? null : $evidence,
                ]);

                $this->audit->record(AuditAction::ContactReviewOpened, $contact, [
                    'review_case_id' => $case->id,
                    'reason' => $reason->value,
                    'candidates' => count($candidateIds),
                ]);

                return $case;
            } catch (UniqueConstraintViolationException) {
                $existing = $this->openCase($contact, $reason);

                if ($existing === null) {
                    throw new \LogicException('An open review case vanished while it was being created.');
                }
            }
        }

        $merged = array_values(array_unique([...$existing->candidate_contact_ids, ...$candidateIds]));
        sort($merged);

        if ($merged !== $existing->candidate_contact_ids) {
            $existing->forceFill(['candidate_contact_ids' => $merged])->save();
        }

        return $existing;
    }

    private function openCase(Contact $contact, ContactReviewReason $reason): ?ContactReviewCase
    {
        return ContactReviewCase::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('reason', $reason)
            ->lockForUpdate()
            ->first();
    }
}
