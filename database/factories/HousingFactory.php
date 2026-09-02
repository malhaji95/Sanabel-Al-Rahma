<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Housing;
use Illuminate\Database\Eloquent\Factories\Factory;

class HousingFactory extends Factory
{
    protected $model = Housing::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'housing_type' => 'owned',
            'monthly_rent' => 0,
            'currency' => 'SYP',
            'habitable_rooms' => 2,
            'safety_band' => 0,
            'services_band' => 0,
            'eviction_band' => 0,
        ];
    }

    public function renting(int $rent = 40000): static
    {
        return $this->state(fn () => ['housing_type' => 'rent', 'monthly_rent' => $rent]);
    }
}
