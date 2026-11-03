<?php

namespace App\Console\Commands;

use App\Services\GiftService;
use Illuminate\Console\Command;

/**
 * T-241 — requirement 8: «إمكانية جدولة الإهداء بتاريخ ووقت محددين أو
 * إهدائه مباشرة».
 *
 * A card with no hour on it is issued the moment its transfer is verified,
 * inside `DonationService::verify()`. This is the other half: the ones with
 * an hour, read often enough that «ten in the morning» means ten.
 */
class IssueDueGifts extends Command
{
    protected $signature = 'sanabel:issue-gifts';

    protected $description = 'Issue gift cards whose scheduled hour has come';

    public function handle(GiftService $gifts): int
    {
        $issued = $gifts->issueDue();

        $this->info("Issued {$issued} gift card(s).");

        return self::SUCCESS;
    }
}
