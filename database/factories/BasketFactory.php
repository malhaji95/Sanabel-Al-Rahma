<?php

namespace Database\Factories;

use App\Models\Basket;
use App\Models\Donor;
use Illuminate\Database\Eloquent\Factories\Factory;

class BasketFactory extends Factory
{
    protected $model = Basket::class;

    public function definition(): array
    {
        return ['donor_id' => Donor::factory(), 'status' => 'open'];
    }
}
