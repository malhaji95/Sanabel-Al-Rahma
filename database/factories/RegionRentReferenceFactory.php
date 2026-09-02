<?php

namespace Database\Factories;

use App\Models\Region;
use App\Models\RegionRentReference;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegionRentReferenceFactory extends Factory
{
    protected $model = RegionRentReference::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'family_size_band' => '4-6',
            'reference_rent' => 40000,
            'currency' => 'SYP',
            'effective_from' => now()->subYear()->toDateString(),
            'version' => 1,
        ];
    }
}
