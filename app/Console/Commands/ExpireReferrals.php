<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;

class ExpireReferrals extends Command
{
    protected $signature = 'sanabel:expire-referrals';

    protected $description = 'Mark referral cards past their expiry as expired';

    public function handle(ReferralService $referrals): int
    {
        $expired = $referrals->expireStale();

        $this->info("Expired {$expired} referral card(s).");

        return self::SUCCESS;
    }
}
