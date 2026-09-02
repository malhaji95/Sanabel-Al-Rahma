<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\HealthRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class HealthRecordFactory extends Factory
{
    protected $model = HealthRecord::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'severity_band' => 25,
            'economic_impact_band' => 25,
            'care_burden_band' => 0,
            'monthly_medical_cost' => 0,
            'currency' => 'SYP',
            'description_ar' => 'حالة مزمنة',
        ];
    }
}
