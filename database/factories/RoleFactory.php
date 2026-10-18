<?php

namespace Database\Factories;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = 'Rol '.Str::title(fake()->unique()->word()).' '.fake()->unique()->numerify('###');

        return [
            'name' => Str::slug($label),
            'label' => $label,
            'description' => fake()->sentence(),
            'guard_name' => 'web',
            'scope' => DataScope::Assigned,
            'is_active' => true,
            'is_root' => false,
        ];
    }

    public function scope(DataScope $scope): static
    {
        return $this->state(fn (array $attributes) => ['scope' => $scope]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Grant the given permissions after creation.
     *
     * @param  list<Permission>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(fn (Role $role) => $role->syncPermissions(
            array_map(fn (Permission $permission): string => $permission->value, $permissions),
        ));
    }
}
