<?php

use App\Models\ContentCard;
use Illuminate\Support\Facades\Blade;

/*
 | T-149 — «نحتاج توسط العبارة تكون بالوسط», 29 October, on the row of ways to
 | give.
 |
 | The name of each way sat at the start of its tile while the button under it
 | was centred, so one small card read as two columns. The block is one column
 | down the middle now.
 */

it('centres the name and the button inside a tile', function () {
    $tile = ContentCard::create([
        'block' => 'ways',
        'title_ar' => 'زكاة واجبة',
        'icon' => 'heart',
        'link' => '/cases',
        'is_published' => true,
        'sort_order' => 1,
    ]);

    $html = Blade::render('<x-home.tiles :tiles="$tiles" />', ['tiles' => collect([$tile])]);

    expect($html)
        ->toContain('زكاة واجبة')
        ->toContain('flex flex-1 flex-col items-center gap-2 p-3 text-center');
});
