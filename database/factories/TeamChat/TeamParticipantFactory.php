<?php

namespace Database\Factories\TeamChat;

use App\Enums\TeamChat\TeamParticipantRole;
use App\Models\TeamChat\TeamParticipant;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamParticipant>
 */
class TeamParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_thread_id' => TeamThread::factory(),
            'user_id' => User::factory(),
            'role' => TeamParticipantRole::Member,
            'joined_at' => now(),
        ];
    }

    public function left(): static
    {
        return $this->state(fn (array $attributes) => ['left_at' => now()]);
    }
}
