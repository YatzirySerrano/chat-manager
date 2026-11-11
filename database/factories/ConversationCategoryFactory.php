<?php

namespace Database\Factories;

use App\Enums\ColorSwatch;
use App\Models\ConversationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationCategory>
 */
class ConversationCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->word()).' '.fake()->numerify('###'),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(ColorSwatch::cases()),
            'is_active' => true,
            'service_rules' => null,
        ];
    }

    /**
     * Indicate that the category has been logically deleted.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Override service rules (flat map of dotted rule key → value).
     *
     * @param  array<string, int|bool>  $rules
     */
    public function withServiceRules(array $rules): static
    {
        return $this->state(fn (array $attributes) => [
            'service_rules' => $rules,
        ]);
    }
}
