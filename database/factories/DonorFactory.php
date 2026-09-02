<?php

namespace Database\Factories;

use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

class DonorFactory extends Factory
{
    protected $model = Donor::class;

    public function definition(): array
    {
        return [
            'name_ar' => 'متبرع '.fake()->unique()->numberBetween(1, 99999),
            'email' => fake()->unique()->safeEmail(),
            'phone_encrypted' => '09'.fake()->numerify('########'),
            'donations_count' => 0,
            'badge' => 'none',
        ];
    }
}
