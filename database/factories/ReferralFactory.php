<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Provider;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'provider_id' => Provider::factory(),
            'code' => strtoupper(fake()->unique()->bothify('REF-####??')),
            'issued_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'issued',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
