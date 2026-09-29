<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Banner> */
class BannerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title_ar' => 'بانر تجريبي',
            'body_ar' => 'نص تعريفي قصير.',
            'is_published' => true,
            'sort_order' => 0,
        ];
    }
}
