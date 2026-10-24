<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\WebChatSession;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebChatSession>
 */
class WebChatSessionFactory extends Factory
{
    /**
     * Define the model's default state: a usable anonymous session started
     * from https://tienda.test. Its token is unknown; use withToken() to
     * receive one in plain text.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory()->webChat(),
            'visitor_id' => 'wv:'.strtolower((string) Str::ulid()),
            'identified' => false,
            'token_hash' => hash('sha256', Str::random(43)),
            'profile' => null,
            'origin' => 'https://tienda.test',
            'landing_url' => 'https://tienda.test/',
            'ip_hash' => WebChatSession::hashIp(fake()->ipv4()),
            'user_agent' => 'Mozilla/5.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Issues the bearer token of the session and hands it in plain text to
     * the callback (only its hash is stored).
     *
     * @param  Closure(string, WebChatSession): void  $receive
     */
    public function withToken(Closure $receive): static
    {
        return $this->afterMaking(function (WebChatSession $session) use ($receive): void {
            $receive($session->generateToken(), $session);
        });
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_seen_at' => now()->subDays(31),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(string $reason = 'visitor_closed'): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now()->subMinute(),
            'revoke_reason' => $reason,
        ]);
    }

    /**
     * Session of a visitor the host site identified with a signed identity.
     */
    public function identified(?string $externalId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'identified' => true,
            'visitor_id' => 'wi:'.substr(hash('sha256', ($attributes['connection_id'] ?? '').'|'.($externalId ?? fake()->uuid())), 0, 48),
            'profile' => ['name' => fake()->name(), 'email' => fake()->safeEmail()],
        ]);
    }
}
