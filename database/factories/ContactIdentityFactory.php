<?php

namespace Database\Factories;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Models\Contact;
use App\Models\ContactIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactIdentity>
 */
class ContactIdentityFactory extends Factory
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
            'channel' => ConnectionChannel::Whatsapp,
            'provider' => ConnectionProvider::MetaCloudApi,
            'external_id' => (string) fake()->unique()->numerify('5255########'),
            'display_name' => fake()->name(),
        ];
    }
}
