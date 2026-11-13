<?php

namespace Modules\Authentication\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+91' . fake()->unique()->numerify('##########'),
            'password' => Hash::make('password'),
            'status' => 'pending',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
