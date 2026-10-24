<?php

namespace Database\Factories;

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * Define the model's default state: a usable token with every ability
     * and no connection allowlisted yet (pass connection_ids). Its secret is
     * unknown; issue one with generateToken() to get it in plain text.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Integración '.fake()->word(),
            'token_hash' => hash('sha256', Str::random(40)),
            'abilities' => ApiAbility::values(),
            'connection_ids' => [],
            'department_ids' => null,
            'allowed_ips' => null,
            'expires_at' => now()->addDays(90),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now()->subMinute(),
            'revoke_reason' => $reason,
        ]);
    }

    /**
     * @param  list<ApiAbility|string>  $abilities
     */
    public function abilities(array $abilities): static
    {
        return $this->state(fn (array $attributes) => [
            'abilities' => array_map(fn (ApiAbility|string $ability): string => $ability instanceof ApiAbility ? $ability->value : $ability, $abilities),
        ]);
    }
}
