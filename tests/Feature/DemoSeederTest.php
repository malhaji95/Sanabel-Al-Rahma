<?php

use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Page;
use App\Models\Post;
use Database\Seeders\SyntheticDataSeeder;

/*
 | The demo seeder has broken twice while the whole suite stayed green, because
 | nothing ran it. Anyone following the deployment guide got an empty system and
 | found out there. One test, so that cannot happen a third time.
 */

beforeEach(function () {
    seedCore();
});

it('fills a fresh system with something to show', function () {
    $this->seed(\Database\Seeders\RegionSeeder::class);
    $this->seed(\Database\Seeders\ReferenceValueSeeder::class);
    $this->seed(SyntheticDataSeeder::class);

    expect(Beneficiary::count())->toBeGreaterThan(0)
        // A donor screen with no published family shows nothing at all.
        ->and(Beneficiary::published()->count())->toBeGreaterThan(0)
        ->and(Campaign::count())->toBeGreaterThan(0)
        ->and(Post::where('status', 'published')->count())->toBeGreaterThan(0)
        ->and(Page::where('is_published', true)->count())->toBeGreaterThan(0);

    // Rule 11 — generated families only, and the seeder says so.
    expect(Beneficiary::first()->file_number)->toStartWith('DEMO-');
});
