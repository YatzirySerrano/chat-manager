<?php

namespace Database\Factories;

use App\Enums\ContactReviewReason;
use App\Models\Contact;
use App\Models\ContactReviewCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactReviewCase>
 */
class ContactReviewCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'reason' => ContactReviewReason::PossibleDuplicate,
            'candidate_contact_ids' => fn (): array => [Contact::factory()->create()->id],
            'evidence' => ['matches' => ['phone']],
        ];
    }

    /**
     * A WhatsApp sender whose phone matched contacts ambiguously.
     */
    public function ambiguousIdentity(): static
    {
        return $this->state(fn (array $attributes) => [
            'reason' => ContactReviewReason::AmbiguousIdentityMatch,
        ]);
    }
}
