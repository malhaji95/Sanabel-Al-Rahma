<?php

namespace Database\Factories;

use App\Models\Region;
use App\Models\RegionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegionRateFactory extends Factory
{
    protected $model = RegionRate::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'person_class' => 'adult',
            'amount' => 5000,
            'currency' => 'SYP',
            'effective_from' => now()->subYear()->toDateString(),
            'version' => 1,
        ];
    }
}
