<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password123'),
            'remember_token' => Str::random(10),
            'city' => 'Abuja',
            'role' => User::ROLE_CUSTOMER,
        ];
    }

    public function tailor(): static
    {
        return $this->state(['role' => User::ROLE_TAILOR]);
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_ADMIN]);
    }

    public function fabricVendor(): static
    {
        return $this->state(['role' => User::ROLE_FABRIC_VENDOR]);
    }
}
