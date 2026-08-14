<?php

namespace Database\Factories\Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'company_id' => 1,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'is_active' => true,
            'failed_logins' => 0,
        ];
    }
}
