#!/usr/bin/env php
<?php

/*
 | T-19 — the basket concurrency check.
 |
 | docs/05-backlog.md calls basket concurrency one of the two places the estimate
 | could move, and docs/06-tests.md asks for "two donors reserving the last
 | remaining amount concurrently — only one succeeds". That cannot be proven
 | inside a RefreshDatabase test, which runs everything on one connection inside
 | one transaction. So this runs it for real: two operating-system processes,
 | two database connections, released at the same wall-clock instant.
 |
 | Run it against a database you can throw away:
 |
 |     php artisan migrate:fresh --seed
 |     php artisan db:seed --class=SyntheticDataSeeder
 |     php scripts/basket-race-check.php [rounds]
 |
 | Exit code 0 means exactly one donor won every round.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\ReservationUnavailable;
use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use App\Models\Donor;
use App\Services\BasketService;
use App\Services\CoverageService;

// ---- child mode: one contender ------------------------------------------------

if (($argv[1] ?? null) === '--contend') {
    [$basketId, $startAt] = [(int) $argv[2], (float) $argv[3]];

    // Spin to the shared instant so both processes really collide.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    try {
        app(BasketService::class)->reserve(Basket::findOrFail($basketId));
        echo "WON\n";
    } catch (ReservationUnavailable) {
        echo "LOST\n";
    } catch (Throwable $e) {
        echo 'ERROR '.get_class($e).': '.$e->getMessage()."\n";
    }

    exit(0);
}

// ---- parent mode: set up each round and referee it -----------------------------

$rounds = max(1, (int) ($argv[1] ?? 10));
$failures = 0;

echo "Basket reservation race — {$rounds} round(s), two processes each.\n\n";

for ($round = 1; $round <= $rounds; $round++) {
    $case = Beneficiary::published()
        ->whereHas('assessments', fn ($q) => $q->where('status', 'approved'))
        ->first();

    if (! $case) {
        fwrite(STDERR, "No published case with an approved assessment. Seed the database first.\n");
        exit(2);
    }

    // Clear last round's holds before reading the remaining need, or the previous
    // reservation is still counted and both contenders end up asking for zero.
    BasketItem::query()->forceDelete();
    Basket::withTrashed()->forceDelete();

    $remaining = app(CoverageService::class)->remainingNeed($case);

    if ($remaining <= 0) {
        fwrite(STDERR, "Case {$case->file_number} has nothing left to fund.\n");
        exit(2);
    }

    // Two donors, each asking for the whole remaining amount. Only one can win.
    $basketIds = [];

    foreach ([1, 2] as $n) {
        $donor = Donor::firstOrCreate(
            ['email' => "race-check-{$n}@sanabel.local"],
            ['name_ar' => "اختبار تزامن {$n}"],
        );

        $basket = Basket::create(['donor_id' => $donor->id, 'status' => 'open']);

        BasketItem::create([
            'basket_id' => $basket->id,
            'beneficiary_id' => $case->id,
            'amount' => $remaining,
            'currency' => config('sanabel.currency'),
        ]);

        $basketIds[] = $basket->id;
    }

    $startAt = microtime(true) + 1.0;
    $processes = [];

    foreach ($basketIds as $basketId) {
        $command = sprintf(
            '%s %s --contend %d %.6f',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__FILE__),
            $basketId,
            $startAt,
        );

        $processes[] = popen($command, 'r');
    }

    $results = [];

    foreach ($processes as $process) {
        $results[] = trim((string) stream_get_contents($process));
        pclose($process);
    }

    $won = count(array_filter($results, fn ($r) => $r === 'WON'));
    $lost = count(array_filter($results, fn ($r) => $r === 'LOST'));
    $reserved = Basket::where('status', 'reserved')->count();

    $ok = $won === 1 && $lost === 1 && $reserved === 1;
    $failures += $ok ? 0 : 1;

    printf(
        "  %s round %2d: amount=%d won=%d lost=%d reserved_rows=%d\n",
        $ok ? 'ok  ' : 'FAIL',
        $round,
        $remaining,
        $won,
        $lost,
        $reserved,
    );

    if (! $ok) {
        printf("       results: %s\n", implode(' | ', $results));
    }
}

BasketItem::query()->forceDelete();
Basket::withTrashed()->forceDelete();

echo "\n";

if ($failures > 0) {
    echo "FAILED: {$failures} round(s) let more than one donor reserve the same amount.\n";
    exit(1);
}

echo "Passed: exactly one donor won every round.\n";
exit(0);
