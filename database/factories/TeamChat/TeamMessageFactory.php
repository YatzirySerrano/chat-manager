<?php

namespace Database\Factories\TeamChat;

use App\Enums\TeamChat\TeamMessageKind;
use App\Models\TeamChat\TeamMessage;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMessage>
 */
class TeamMessageFactory extends Factory
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
            'kind' => TeamMessageKind::Message,
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A message of the thread written by the user; it also becomes the
     * thread's latest message.
     */
    public function in(TeamThread $thread, User $author): static
    {
        return $this->state(fn (array $attributes) => [
            'team_thread_id' => $thread->id,
            'user_id' => $author->id,
        ])->afterCreating(function (TeamMessage $message) use ($thread): void {
            $thread->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();
        });
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => ['body' => null, 'deleted_at' => now()]);
    }
}
