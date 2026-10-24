<?php

namespace Database\Factories;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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
            'role' => UserRole::GUEST,
            'view_mode' => 'stocks',
            'credit_balance' => 0.00,
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
     * Indicate that the user has guest role.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::GUEST,
            'view_mode' => 'stocks',
        ]);
    }

    /**
     * Indicate that the user has equities subscription role.
     */
    public function equities(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::EQUITIES,
            'view_mode' => 'stocks',
        ]);
    }

    /**
     * Indicate that the user has crypto subscription role.
     */
    public function crypto(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::CRYPTO,
            'view_mode' => 'crypto',
        ]);
    }

    /**
     * Indicate that the user has full subscription role.
     */
    public function fullSubscription(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::FULL_SUBSCRIPTION,
            'view_mode' => 'stocks',
        ]);
    }

    /**
     * Indicate that the user has administrator role.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::ADMINISTRATOR,
            'view_mode' => 'stocks',
        ]);
    }

    /**
     * Alias for administrator role.
     */
    public function admin(): static
    {
        return $this->administrator();
    }

    /**
     * Indicate that the user has a specific dollar credit balance.
     */
    public function withCredit(float|int|string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'credit_balance' => round((float) $amount, 2),
        ]);
    }
}
