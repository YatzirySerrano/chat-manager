<?php

namespace Database\Factories;

use App\Enums\AgentAvailability;
use App\Enums\DefaultRole;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the account has been deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Assign the administrator role.
     */
    public function administrator(): static
    {
        return $this->withRole(DefaultRole::Administrator);
    }

    /**
     * Assign the supervisor role.
     */
    public function supervisor(): static
    {
        return $this->withRole(DefaultRole::Supervisor);
    }

    /**
     * Assign the agent role.
     */
    public function agent(): static
    {
        return $this->withRole(DefaultRole::Agent);
    }

    /**
     * Declare the user available to receive automatic assignments.
     */
    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability' => AgentAvailability::Available,
            'availability_changed_at' => now(),
        ]);
    }

    /**
     * Assign the given application role after creation.
     */
    public function withRole(DefaultRole|Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(
            $role instanceof Role ? $role : Role::findByName($role->value, 'web'),
        ));
    }

    /**
     * Attach the user to the given departments after creation.
     *
     * @param  Department|iterable<Department>  $departments
     */
    public function inDepartments(Department|iterable $departments): static
    {
        $departments = $departments instanceof Department ? [$departments] : $departments;

        return $this->afterCreating(fn (User $user) => $user->departments()->attach(
            collect($departments)->map(fn (Department $department) => $department->id)->all(),
        ));
    }
}
