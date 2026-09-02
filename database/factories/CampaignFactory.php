<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Fund;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'title_ar' => 'حملة '.fake()->unique()->numberBetween(1, 99999),
            'goal_amount' => 100000,
            'collected_amount' => 0,
            'reserved_amount' => 0,
            'currency' => 'SYP',
            'status' => 'active',
            'is_published' => false,
            'fund_id' => fn () => Fund::byKey(Fund::OPERATIONAL)->id,
        ];
    }
}
