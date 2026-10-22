<?php

namespace Database\Factories\TeamChat;

use App\Enums\TeamChat\TeamParticipantRole;
use App\Enums\TeamChat\TeamThreadType;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamThread>
 */
class TeamThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TeamThreadType::Group,
            'name' => fake()->unique()->words(2, true),
            'description' => null,
        ];
    }

    /**
     * A direct chat between the two users (both become participants).
     */
    public function direct(User $first, User $second): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TeamThreadType::Direct,
            'name' => null,
            'direct_key' => TeamThread::directKeyFor($first->id, $second->id),
        ])->afterCreating(function (TeamThread $thread) use ($first, $second): void {
            foreach ([$first, $second] as $user) {
                $thread->participations()->create(['user_id' => $user->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()]);
            }
        });
    }

    /**
     * A group with the given members; the admins also become participants.
     *
     * @param  list<User>  $members
     * @param  list<User>  $admins
     */
    public function withParticipants(array $members, array $admins = []): static
    {
        return $this->afterCreating(function (TeamThread $thread) use ($members, $admins): void {
            foreach ($admins as $admin) {
                $thread->participations()->create(['user_id' => $admin->id, 'role' => TeamParticipantRole::Admin, 'joined_at' => now()]);
            }

            foreach ($members as $member) {
                $thread->participations()->create(['user_id' => $member->id, 'role' => TeamParticipantRole::Member, 'joined_at' => now()]);
            }
        });
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
