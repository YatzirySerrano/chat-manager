<?php

namespace Database\Factories;

use App\Enums\ResponseCycleKind;
use App\Models\Conversation;
use App\Models\ResponseCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponseCycle>
 */
class ResponseCycleFactory extends Factory
{
    /**
     * Define the model's default state: an open, unmeasured first-response
     * cycle. The dimensions are copied from the conversation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'kind' => ResponseCycleKind::FirstResponse,
            'connection_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->value('connection_id'),
            'department_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->value('department_id'),
            'category_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->value('category_id'),
            'assigned_user_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->value('assigned_user_id'),
            'started_at' => now(),
            'target_seconds' => null,
            'business_hours_only' => true,
            'active' => 1,
        ];
    }

    /**
     * Measure the cycle against an objective counted in calendar time.
     */
    public function withTarget(int $seconds, int $warningPercent = 80): static
    {
        return $this->state(fn (array $attributes) => [
            'target_seconds' => $seconds,
            'business_hours_only' => false,
            'warn_at' => CarbonImmutable::parse($attributes['started_at'])->addSeconds(intdiv($seconds * $warningPercent, 100)),
            'due_at' => CarbonImmutable::parse($attributes['started_at'])->addSeconds($seconds),
        ]);
    }

    /**
     * The deadline was exceeded.
     */
    public function breached(): static
    {
        return $this->state(fn (array $attributes) => [
            'breached_at' => $attributes['due_at'] ?? now(),
            'breach_user_id' => $attributes['assigned_user_id'] ?? null,
        ]);
    }

    /**
     * The team answered and the cycle ended.
     */
    public function answered(?User $responder = null, int $afterSeconds = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'responded_at' => CarbonImmutable::parse($attributes['started_at'])->addSeconds($afterSeconds),
            'ended_at' => CarbonImmutable::parse($attributes['started_at'])->addSeconds($afterSeconds),
            'responder_user_id' => $responder?->id,
            'outcome' => ResponseCycle::OUTCOME_ANSWERED,
            'response_seconds' => $afterSeconds,
            'business_response_seconds' => $afterSeconds,
            'active' => null,
        ]);
    }

    /**
     * The conversation was closed before anyone answered.
     */
    public function closedUnanswered(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now(),
            'outcome' => ResponseCycle::OUTCOME_CLOSED,
            'active' => null,
        ]);
    }
}
