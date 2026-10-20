<?php

namespace Database\Factories;

use App\Enums\AttributionReliability;
use App\Enums\ContactOriginType;
use App\Models\Contact;
use App\Models\ContactAttribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAttribution>
 */
class ContactAttributionFactory extends Factory
{
    /**
     * Define the model's default state: an origin declared by a person.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'origin_type' => fake()->randomElement([ContactOriginType::Website, ContactOriginType::QrCode, ContactOriginType::Referral]),
            'reliability' => AttributionReliability::ManuallyDeclared,
            'captured_at' => now(),
            'recorded_by_user_id' => User::factory(),
        ];
    }

    /**
     * A Click-to-WhatsApp ad referral confirmed by Meta.
     */
    public function fromMetaAd(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin_type' => ContactOriginType::FacebookAd,
            'reliability' => AttributionReliability::ProviderConfirmed,
            'provider' => 'meta',
            'ad_platform' => 'facebook',
            'source_id' => (string) fake()->numerify('120############'),
            'source_type' => 'ad',
            'source_url' => 'https://fb.me/'.fake()->lexify('????????'),
            'headline' => fake()->sentence(4),
            'body' => fake()->sentence(),
            'ctwa_clid' => fake()->sha1(),
            'media_type' => 'image',
            'recorded_by_user_id' => null,
        ]);
    }
}
