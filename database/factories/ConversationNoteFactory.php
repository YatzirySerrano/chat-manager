<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationNote>
 */
class ConversationNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
