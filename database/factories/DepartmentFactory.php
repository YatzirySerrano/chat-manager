<?php

namespace Database\Factories;

use App\Enums\ColorSwatch;
use App\Enums\DistributionMode;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word()).' '.fake()->numerify('###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(ColorSwatch::cases()),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the department has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Use the given way of distributing pending conversations.
     */
    public function distribution(DistributionMode $mode): static
    {
        return $this->state(fn (array $attributes) => [
            'distribution_mode' => $mode,
        ]);
    }
}
