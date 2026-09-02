<?php

namespace Database\Factories;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Factories\Factory;

class BasketItemFactory extends Factory
{
    protected $model = BasketItem::class;

    public function definition(): array
    {
        return [
            'basket_id' => Basket::factory(),
            'beneficiary_id' => Beneficiary::factory(),
            'amount' => 5000,
            'currency' => 'SYP',
        ];
    }
}
