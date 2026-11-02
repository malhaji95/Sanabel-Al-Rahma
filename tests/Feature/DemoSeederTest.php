<?php

use App\Console\Commands\ResetDemo;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Distribution;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\RankingService;
use Database\Seeders\ReferenceValueSeeder;
use Database\Seeders\RegionSeeder;
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
    $this->seed(RegionSeeder::class);
    $this->seed(ReferenceValueSeeder::class);
    $this->seed(SyntheticDataSeeder::class);

    expect(Beneficiary::count())->toBeGreaterThan(0)
        // A donor screen with no published family shows nothing at all.
        ->and(Beneficiary::published()->count())->toBeGreaterThan(0)
        ->and(Campaign::count())->toBeGreaterThan(0)
        ->and(Post::where('status', 'published')->count())->toBeGreaterThan(0)
        ->and(Page::where('is_published', true)->count())->toBeGreaterThan(0);

    // Rule 11 — generated families only, and the seeder says so.
    expect(Beneficiary::first()->file_number)->toStartWith('DEMO-');
})->group('DATA-06');

/*
 | T-97 — the demonstration was flat, and the rebuild that fixes it must not
 | be something a deploy can do by accident.
 */

it('leaves the demonstration showing every coverage band, not a column of zeroes', function () {
    $this->seed(RegionSeeder::class);
    $this->seed(ReferenceValueSeeder::class);
    $this->seed(SyntheticDataSeeder::class);

    $rows = app(RankingService::class)->fundingList('monthly', null, withCovered: true);

    $bands = collect($rows)->map(function ($row) {
        $row = (array) $row;
        $need = (int) $row['need'];
        $percent = $need > 0 ? intdiv((int) $row['confirmed'] * 100, $need) : 0;

        return match (true) {
            $percent >= 100 => 'complete',
            $percent >= 61 => 'high',
            $percent >= 41 => 'middle',
            $percent > 0 => 'low',
            default => 'none',
        };
    })->countBy();

    /*
     | The bands, the priority colours and the «مكتملة» state are the whole of
     | what the donor list does, and they were all invisible: the seeder funded
     | four families and left thirty reading 0%. A visitor saw the screens and
     | none of the behaviour.
     */
    foreach (['complete', 'high', 'middle', 'low', 'none'] as $band) {
        expect($bands->get($band, 0))->toBeGreaterThan(0, "no family sits in the {$band} band");
    }

    // And the wallet a transfer goes to, without which the donor portal says
    // no wallet has been chosen.
    expect(Setting::value('platform_wallet')['number'] ?? null)->not->toBeEmpty();
})->group('DATA-06');

it('refuses to empty a database that is not a demonstration', function () {
    config(['sanabel.demo_seed' => false]);

    $this->artisan('demo:reset', ['--force' => true])->assertFailed();

    // And even where it is one, not without being asked twice.
    config(['sanabel.demo_seed' => true]);

    $this->artisan('demo:reset')->assertFailed();

    expect(Beneficiary::count())->toBe(0);
})->group('DATA-06');

it('rebuilds once per token, so leaving the switch set does not wipe every deploy', function () {
    config(['sanabel.demo_seed' => true]);

    // The token of a rebuild that already happened. The command must stop
    // here, before it drops a single table — which is what this asserts by
    // the fact that the seeded rows below are still standing.
    Setting::put(ResetDemo::TOKEN_SETTING, 'october-14');

    Campaign::factory()->create();

    $this->artisan('demo:reset', ['--force' => true, '--token' => 'october-14'])->assertSuccessful();

    expect(Campaign::count())->toBe(1);
})->group('DATA-06');

/*
 | T-231 — the distribution the demonstration ships with has a list in it.
 |
 | T-178 moved its region off one family's village and onto that family's
 | area, and stopped there. The family it read was `$cases[0]`, which is a
 | one-time file — every third one is, counting from the first — while the
 | list is drawn from uncovered monthly families. So the area was the area
 | of a file that could never be on the list, and whenever no uncovered
 | monthly family lived under it, `generateList()` returned nothing and
 | `approve()` refused it. It surfaced once in a full run as «Generate the
 | list before approving it» — a flake in the demonstration, not in the
 | rule, and a seeder that fails one run in some number is a seeder nobody
 | trusts.
 |
 | On the honest limits of this test: the seeder's families are random and
 | are not seeded from a fixed value, so nothing asserted about its output
 | is deterministic. This test passed under the old code too, on the run
 | that was tried — it had to, or the flake would have been a failure every
 | time and would never have reached a full suite. What the fix changes is
 | that it now passes on every arrangement rather than on most of them,
 | because the area is read off a family the ranking returns for the same
 | support type the list uses. That is verified by reading the seeder, not
 | by this test going red.
 */

it('ships a distribution whose list is not empty, every run', function () {
    $this->seed(RegionSeeder::class);
    $this->seed(ReferenceValueSeeder::class);
    $this->seed(SyntheticDataSeeder::class);

    $distribution = Distribution::firstOrFail();

    expect($distribution->list_json)->not->toBeEmpty()
        // Approved is what freezes the list into rows somebody can work.
        ->and($distribution->status)->not->toBe('draft');
})->group('DATA-06');
