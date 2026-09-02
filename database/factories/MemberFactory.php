<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'membership_no' => 'M-'.fake()->unique()->numberBetween(10000, 99999),
            'name_ar' => 'عضو '.fake()->numberBetween(1, 9999),
            'category' => 'basic',
            'status' => 'active',
            'joined_at' => now()->subMonths(3)->toDateString(),
        ];
    }
}
