<?php

namespace Database\Factories;

use App\Models\WhatsappAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappAccount>
 */
class WhatsappAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'display_phone_number' => fake()->e164PhoneNumber(),
            'phone_number_id' => (string) fake()->unique()->numerify('1###############'),
            'business_account_id' => (string) fake()->numerify('1###############'),
            'is_active' => true,
        ];
    }
}
