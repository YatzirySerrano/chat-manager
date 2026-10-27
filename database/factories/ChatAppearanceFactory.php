<?php

namespace Database\Factories;

use App\Models\ChatAppearance;
use App\Models\Conversation;
use App\Models\TeamChat\TeamThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatAppearance>
 */
class ChatAppearanceFactory extends Factory
{
    /**
     * Define the model's default state: the user's default appearance for
     * every chat, with readable custom colors.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'conversation_id' => null,
            'preset' => null,
            'colors' => [
                'light' => ['sent_bg' => '#DCF8C6', 'sent_text' => '#111111', 'received_bg' => '#FFFFFF', 'received_text' => '#111111', 'chat_bg' => '#F4F4F5'],
                'dark' => ['sent_bg' => '#005C4B', 'sent_text' => '#FFFFFF', 'received_bg' => '#202C33', 'received_text' => '#FFFFFF', 'chat_bg' => '#0B141A'],
            ],
        ];
    }

    /**
     * An override for one conversation.
     */
    public function forConversation(?Conversation $conversation = null): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversation === null ? Conversation::factory() : $conversation->id,
        ]);
    }

    /**
     * An override for one internal chat.
     */
    public function forTeamThread(?TeamThread $thread = null): static
    {
        return $this->state(fn (array $attributes) => [
            'team_thread_id' => $thread === null ? TeamThread::factory() : $thread->id,
        ]);
    }
}
