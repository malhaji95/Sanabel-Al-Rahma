<?php

namespace Database\Factories;

use App\Models\GovernanceDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class GovernanceDocumentFactory extends Factory
{
    protected $model = GovernanceDocument::class;

    public function definition(): array
    {
        return [
            'title_ar' => 'وثيقة '.fake()->unique()->numberBetween(1, 999999),
            'kind' => 'policy',
            'classification' => 'internal',
            'status' => 'draft',
            'requires_acknowledgement' => false,
        ];
    }

    public function confidential(): static
    {
        return $this->state(fn () => ['classification' => 'confidential']);
    }
}
