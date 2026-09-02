<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Income;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeFactory extends Factory
{
    protected $model = Income::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'source_type' => 'work',
            'amount' => 3000,
            'currency' => 'SYP',
            'is_stable' => true,
        ];
    }
}
