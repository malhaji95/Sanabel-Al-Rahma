<?php

namespace Database\Factories;

use App\Models\GiftCard;
use Illuminate\Database\Eloquent\Factories\Factory;

class GiftCardFactory extends Factory
{
    protected $model = GiftCard::class;

    public function definition(): array
    {
        return [
            'name_ar' => 'تصميم '.fake()->numberBetween(1, 999),
            'image_path' => 'gift-cards/design.webp',
            'is_published' => true,
            'sort_order' => 0,
        ];
    }
}
