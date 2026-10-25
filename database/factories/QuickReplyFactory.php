<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReply>
 */
class QuickReplyFactory extends Factory
{
    /**
     * Define the model's default state: an active reply available to everyone.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'shortcut' => fake()->unique()->lexify('????').fake()->numerify('##'),
            'body' => 'Hola {contacto.nombre}, '.fake()->sentence(),
            'is_active' => true,
            'is_global' => true,
        ];
    }

    /**
     * Indicate that the reply has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A personal reply of the given user: not global and without departments.
     */
    public function personal(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'is_global' => false,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
    }

    /**
     * Share the reply only with the given departments.
     */
    public function forDepartments(Department ...$departments): static
    {
        return $this->state(fn (array $attributes) => [
            'is_global' => false,
        ])->afterCreating(fn (QuickReply $reply) => $reply->departments()->attach(array_map(fn (Department $department): int => $department->id, $departments)));
    }
}
