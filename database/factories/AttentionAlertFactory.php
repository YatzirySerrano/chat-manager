<?php

namespace Database\Factories;

use App\Enums\AttentionAlertAudience;
use App\Enums\AttentionAlertPriority;
use App\Enums\AttentionAlertSound;
use App\Enums\ColorSwatch;
use App\Models\AttentionAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttentionAlert>
 */
class AttentionAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory()->administrator(),
            'client_key' => (string) Str::uuid(),
            'audience' => AttentionAlertAudience::Individual,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'priority' => AttentionAlertPriority::Info,
            'color' => ColorSwatch::Blue,
            'sound' => AttentionAlertSound::Priority,
            'action' => null,
            'recipients_count' => 0,
        ];
    }

    public function urgent(): static
    {
        return $this->state(fn (): array => ['priority' => AttentionAlertPriority::Urgent]);
    }
}
