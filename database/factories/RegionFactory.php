<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegionFactory extends Factory
{
    protected $model = Region::class;

    public function definition(): array
    {
        return [
            'name_ar' => 'منطقة '.fake()->unique()->numberBetween(1, 99999),
            'type' => 'area',
            'is_active' => true,
        ];
    }

    public function governorate(): static
    {
        return $this->state(fn () => ['type' => 'governorate']);
    }
}
