<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $waId = (string) fake()->unique()->numerify('521##########');

        return [
            'wa_id' => $waId,
            'phone_number' => '+'.$waId,
            'profile_name' => fake()->name(),
        ];
    }
}
