<?php

namespace Database\Factories;

use App\Enums\ConnectionChannel;
use App\Enums\ConnectionProvider;
use App\Enums\WebChatAvailabilityMode;
use App\Enums\WebChatIdentityMode;
use App\Enums\WebChatPosition;
use App\Models\Connection;
use App\Models\WebChatWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebChatWidget>
 */
class WebChatWidgetFactory extends Factory
{
    /**
     * Define the model's default state: an anonymous widget of a web chat
     * connection that https://tienda.test may embed.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory()->state([
                'channel' => ConnectionChannel::WebChat,
                'provider' => ConnectionProvider::WebWidget,
                'display_phone_number' => null,
                'phone_number_id' => null,
                'business_account_id' => null,
                'access_token' => null,
                'app_secret' => null,
                'webhook_verify_token' => null,
            ]),
            'allowed_origins' => ['https://tienda.test'],
            'title' => 'Chatea con nosotros',
            'subtitle' => 'Te respondemos en minutos',
            'welcome_text' => '¡Hola! ¿En qué podemos ayudarte?',
            'offline_text' => 'Ahora no hay nadie disponible. Déjanos tu mensaje y te responderemos.',
            'position' => WebChatPosition::Right,
            'accent_color' => '#2563eb',
            'launcher_enabled' => true,
            'launcher_label' => null,
            'identity_mode' => WebChatIdentityMode::Anonymous,
            'prechat' => null,
            'availability_mode' => WebChatAvailabilityMode::Always,
            'session_idle_days' => 30,
        ];
    }

    /**
     * Widget without authorized origins (the connection is not configured).
     */
    public function withoutOrigins(): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_origins' => [],
        ]);
    }

    /**
     * Widget whose visitors may or must be identified by the host site.
     */
    public function identityMode(WebChatIdentityMode $mode): static
    {
        return $this->state(fn (array $attributes) => [
            'identity_mode' => $mode,
        ]);
    }
}
