<?php

namespace App\Console\Commands;

use App\Services\BasketService;
use Illuminate\Console\Command;

/** docs/03-rules.md §6 — expired baskets are released every five minutes. */
class ReleaseExpiredBaskets extends Command
{
    protected $signature = 'sanabel:release-expired-baskets';

    protected $description = 'Release basket reservations whose hold has expired';

    public function handle(BasketService $baskets): int
    {
        // Warn first, release second: a hold warned about in this same pass
        // would otherwise be released before the donor ever heard of it.
        $warned = $baskets->warnExpiring();
        $released = $baskets->releaseExpired();

        $this->info("Warned {$warned} donor(s); released {$released} expired reservation(s).");

        return self::SUCCESS;
    }
}
