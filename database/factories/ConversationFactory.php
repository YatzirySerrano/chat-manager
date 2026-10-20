<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'contact_id' => Contact::factory(),
            'department_id' => null,
            'assigned_user_id' => null,
            'status' => ConversationStatus::Pending,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ];
    }

    /**
     * Place the conversation in a department queue.
     */
    public function inDepartment(Department $department): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department->id,
        ]);
    }

    /**
     * Assign the conversation to an agent.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_user_id' => $user->id,
            'status' => ConversationStatus::Open,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Mark the conversation as closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
