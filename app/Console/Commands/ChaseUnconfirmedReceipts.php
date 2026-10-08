<?php

namespace App\Console\Commands;

use App\Services\DisbursementService;
use Illuminate\Console\Command;

/**
 * Silence is not confirmation. A payment the household never answered for
 * inside its window goes back to the finance desk, the same way an objection
 * does (decision of 6 October).
 */
class ChaseUnconfirmedReceipts extends Command
{
    protected $signature = 'sanabel:chase-receipts';

    protected $description = 'Send unanswered disbursements back to finance once their window has passed';

    public function handle(DisbursementService $disbursements): int
    {
        $sent = $disbursements->chaseUnconfirmed();

        $this->info("Sent {$sent} unanswered payment(s) back for review.");

        return self::SUCCESS;
    }
}
