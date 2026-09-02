<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Synthetic families only — no real data in dev or test (rule 11). */
class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    public function definition(): array
    {
        $nationalId = (string) fake()->unique()->numberBetween(10000000000, 99999999999);

        return [
            'file_number' => 'F-'.fake()->unique()->numberBetween(100000, 999999),
            'national_id_encrypted' => $nationalId,
            'national_id_hash' => Beneficiary::hashNationalId($nationalId),
            'first_name' => 'اسم'.fake()->numberBetween(1, 999),
            'father_name' => 'أب'.fake()->numberBetween(1, 999),
            'family_name' => 'عائلة'.fake()->numberBetween(1, 999),
            'phone_encrypted' => '09'.fake()->numerify('########'),
            'region_id' => Region::factory(),
            'marital_status' => 'married',
            'support_type' => 'monthly',
            'status' => 'draft',
            'source' => 'delegate',
            'documented_debt' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'approved_at' => now()->subDays(10),
            'published_at' => now()->subDays(10),
        ]);
    }
}
