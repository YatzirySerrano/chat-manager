<?php

namespace Database\Factories;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state: a contact added by hand, saved in
     * the directory.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone_e164' => '+52'.fake()->unique()->numerify('55########'),
            'email' => null,
            'status' => ContactStatus::Active,
            'source' => ContactSource::Manual,
            'saved_at' => now(),
        ];
    }

    /**
     * A contact registered automatically from an unknown sender and not
     * saved into the directory yet.
     */
    public function provisional(): static
    {
        return $this->state(fn (array $attributes) => [
            'saved_at' => null,
            'saved_by_user_id' => null,
        ]);
    }

    /**
     * A contact that wrote through WhatsApp, with its WhatsApp identity.
     */
    public function fromWhatsapp(?string $waId = null): static
    {
        $waId ??= (string) fake()->unique()->numerify('5255########');

        return $this->state(fn (array $attributes) => [
            'phone_e164' => '+'.$waId,
            'profile_name' => $attributes['name'] ?? fake()->name(),
            'source' => ContactSource::Whatsapp,
            'first_interaction_at' => now()->subDay(),
            'last_interaction_at' => now(),
        ])->afterCreating(fn (Contact $contact) => $contact->identities()->create([
            'channel' => ConnectionChannel::Whatsapp,
            'provider' => ConnectionProvider::MetaCloudApi,
            'external_id' => $waId,
            'display_name' => $contact->profile_name,
        ]));
    }

    /**
     * Indicate that the contact has been archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    /**
     * Record the user that created the contact.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $user->id,
        ]);
    }
}
