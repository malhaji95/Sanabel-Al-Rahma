<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'name_ar' => 'مزود '.fake()->unique()->numberBetween(1, 99999),
            'type' => 'clinic',
            'specialty_ar' => 'عام',
            'region_id' => Region::factory(),
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'valid_until' => now()->addYear()->toDateString(),
            'status' => 'active',
        ];
    }
}
