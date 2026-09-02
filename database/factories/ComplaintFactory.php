<?php

namespace Database\Factories;

use App\Models\Complaint;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        return [
            'reference_no' => 'C-'.fake()->unique()->numberBetween(100000, 999999),
            'subject_ar' => 'شكوى',
            'body_ar' => 'نص الشكوى',
            'category' => 'service',
            'status' => 'new',
        ];
    }
}
