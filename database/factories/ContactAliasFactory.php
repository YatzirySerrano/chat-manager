<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAlias>
 */
class ContactAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'contact_id' => Contact::factory(),
            'alias' => fake()->firstName().' '.fake()->word(),
        ];
    }
}
