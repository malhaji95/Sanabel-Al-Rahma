<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\JobProfile;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobProfileFactory extends Factory
{
    protected $model = JobProfile::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'trade_key' => 'carpentry',
            'summary_ar' => 'خبرة في النجارة',
            'region_id' => Region::factory(),
            'availability' => 'full_time',
            'status' => 'pending',
        ];
    }
}
