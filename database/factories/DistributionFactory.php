<?php

namespace Database\Factories;

use App\Models\Distribution;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

class DistributionFactory extends Factory
{
    protected $model = Distribution::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'title_ar' => 'توزيع '.fake()->unique()->numberBetween(1, 99999),
            'total_amount' => 500000,
            'per_family_amount' => 50000,
            'currency' => 'SYP',
            'status' => 'draft',
        ];
    }
}
