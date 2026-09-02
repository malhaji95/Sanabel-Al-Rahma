<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'client_uuid' => (string) Str::uuid(),
            'visited_at' => now(),
            'note_ar' => 'زيارة ميدانية',
            'recommendation' => 'approve',
            'is_reassessment' => false,
        ];
    }
}
