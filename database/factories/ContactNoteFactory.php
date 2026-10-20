<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactNote>
 */
class ContactNoteFactory extends Factory
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
            'author_user_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
