<?php

namespace Database\Factories;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\WebChatWidget;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * Define the model's default state: an active WhatsApp connection whose
     * credentials are saved but were never verified.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'channel' => ConnectionChannel::Whatsapp,
            'provider' => ConnectionProvider::MetaCloudApi,
            'display_phone_number' => fake()->e164PhoneNumber(),
            'phone_number_id' => (string) fake()->unique()->numerify('1###############'),
            'business_account_id' => (string) fake()->numerify('1###############'),
            'access_token' => 'EAAG'.Str::random(60),
            'app_secret' => Str::random(32),
            'webhook_verify_token' => Str::random(40),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the connection has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A connection saved without Meta identifiers or credentials.
     */
    public function unconfigured(): static
    {
        return $this->state(fn (array $attributes) => [
            'display_phone_number' => null,
            'phone_number_id' => null,
            'business_account_id' => null,
            'access_token' => null,
            'app_secret' => null,
            'webhook_verify_token' => null,
        ]);
    }

    /**
     * Credentials accepted by Meta.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'credentials_verified_at' => now()->subDays(2),
            'credentials_error' => null,
            'verified_name' => $attributes['name'] ?? fake()->company(),
            'phone_status' => 'CONNECTED',
            'quality_rating' => 'GREEN',
        ]);
    }

    /**
     * Credentials rejected by Meta in the last verification.
     */
    public function withInvalidCredentials(): static
    {
        return $this->state(fn (array $attributes) => [
            'credentials_verified_at' => null,
            'credentials_error' => 'Invalid OAuth access token.',
        ]);
    }

    /**
     * Inbound and outbound messages confirmed.
     */
    public function operational(): static
    {
        return $this->verified()->state(fn (array $attributes) => [
            'webhook_verified_at' => now()->subDay(),
            'last_inbound_at' => now()->subHour(),
            'last_outbound_at' => now()->subMinutes(30),
            'last_activity_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * An active web chat connection: no Meta identifiers or credentials and a
     * widget authorized for https://tienda.test, so it may operate.
     */
    public function webChat(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => ConnectionChannel::WebChat,
            'provider' => ConnectionProvider::WebWidget,
            'display_phone_number' => null,
            'phone_number_id' => null,
            'business_account_id' => null,
            'access_token' => null,
            'app_secret' => null,
            'webhook_verify_token' => null,
            'is_active' => true,
        ])->has(WebChatWidget::factory(), 'webChatWidget');
    }

    /**
     * An active Facebook Messenger connection authorized with Meta: a page
     * id and its page token (never expires), no WhatsApp identifiers.
     */
    public function messenger(): static
    {
        return $this->metaAccount(ConnectionChannel::Messenger, ConnectionProvider::MetaMessenger, null);
    }

    /**
     * An active Instagram connection authorized with Instagram Login: the
     * professional account id and a long-lived token expiring in 60 days.
     */
    public function instagram(): static
    {
        return $this->metaAccount(ConnectionChannel::Instagram, ConnectionProvider::MetaInstagram, now()->addDays(60));
    }

    private function metaAccount(ConnectionChannel $channel, ConnectionProvider $provider, mixed $expiresAt): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => $channel,
            'provider' => $provider,
            'display_phone_number' => null,
            'phone_number_id' => null,
            'business_account_id' => null,
            'app_secret' => null,
            'webhook_verify_token' => null,
            'is_active' => true,
        ])->afterMaking(function (Connection $connection) use ($expiresAt): void {
            $connection->forceFill([
                'external_account_id' => (string) fake()->unique()->numerify('17841#########'),
                'external_account_name' => fake()->company(),
                'granted_scopes' => $connection->channel === ConnectionChannel::Instagram
                    ? ['instagram_business_basic', 'instagram_business_manage_messages']
                    : ['pages_show_list', 'pages_messaging', 'pages_manage_metadata', 'pages_read_engagement'],
                'token_expires_at' => $expiresAt,
                'authorized_at' => now()->subDays(2),
                'credentials_verified_at' => now()->subDays(2),
                'app_subscribed' => true,
            ]);
        });
    }
}
