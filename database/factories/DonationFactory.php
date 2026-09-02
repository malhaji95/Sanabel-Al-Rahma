<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\Fund;
use Illuminate\Database\Eloquent\Factories\Factory;

class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'route' => 'platform',
            'amount' => 10000,
            'currency' => 'SYP',
            'transaction_ref' => 'TRX-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => 'pending',
            'fund_id' => fn () => Fund::byKey(Fund::OPERATIONAL)->id,
        ];
    }

    public function membershipFund(): static
    {
        return $this->state(fn () => ['fund_id' => Fund::byKey(Fund::MEMBERSHIP)->id]);
    }
}
