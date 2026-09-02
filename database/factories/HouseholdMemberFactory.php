<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\HouseholdMember;
use Illuminate\Database\Eloquent\Factories\Factory;

class HouseholdMemberFactory extends Factory
{
    protected $model = HouseholdMember::class;

    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'relation' => 'child',
            'name_ar' => 'فرد'.fake()->numberBetween(1, 9999),
            'birth_year' => (int) date('Y') - 30,
            'gender' => fake()->randomElement(['male', 'female']),
            'person_class' => 'adult',
            'dependent' => false,
            'unable_to_earn' => false,
            'is_student' => false,
            'has_documented_condition' => false,
        ];
    }

    public function adult(): static
    {
        return $this->state(fn () => [
            'person_class' => 'adult',
            'relation' => 'parent',
            'birth_year' => (int) date('Y') - 35,
        ]);
    }

    public function child(): static
    {
        return $this->state(fn () => [
            'person_class' => 'child',
            'relation' => 'child',
            'birth_year' => (int) date('Y') - 8,
            'dependent' => true,
        ]);
    }

    public function elderly(): static
    {
        return $this->state(fn () => [
            'person_class' => 'elderly',
            'relation' => 'grandparent',
            'birth_year' => (int) date('Y') - 70,
            'dependent' => true,
        ]);
    }
}
