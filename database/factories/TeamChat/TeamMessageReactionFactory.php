<?php

namespace Database\Factories\TeamChat;

use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamMessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMessageReaction>
 */
class TeamMessageReactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_message_id' => TeamMessage::factory(),
            'user_id' => User::factory(),
            'emoji' => '👍',
        ];
    }
}
