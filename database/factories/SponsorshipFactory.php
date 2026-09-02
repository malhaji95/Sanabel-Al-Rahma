<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Donor;
use App\Models\Sponsorship;
use Illuminate\Database\Eloquent\Factories\Factory;

class SponsorshipFactory extends Factory
{
    protected $model = Sponsorship::class;

    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'beneficiary_id' => Beneficiary::factory(),
            'amount' => 20000,
            'currency' => 'SYP',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addMonths(5)->toDateString(),
            'status' => 'active',
        ];
    }
}
