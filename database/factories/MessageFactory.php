<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'whatsapp_message_id' => 'wamid.'.fake()->unique()->sha1(),
            'direction' => MessageDirection::Inbound,
            'type' => MessageType::Text,
            'status' => MessageStatus::Received,
            'body' => fake()->sentence(),
        ];
    }
}
