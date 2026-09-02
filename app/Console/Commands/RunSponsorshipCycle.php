<?php

namespace App\Console\Commands;

use App\Services\SponsorshipService;
use Illuminate\Console\Command;

/** docs/03-rules.md §8 — reminders, overdue marking and the lapse rule. */
class RunSponsorshipCycle extends Command
{
    protected $signature = 'sanabel:sponsorship-cycle';

    protected $description = 'Send installment reminders, mark overdue installments and lapse sponsorships';

    public function handle(SponsorshipService $sponsorships): int
    {
        $reminded = $sponsorships->sendReminders();
        $result = $sponsorships->markOverdueAndLapse();

        $this->info("Reminded {$reminded}, overdue {$result['overdue']}, lapsed {$result['lapsed']}.");

        return self::SUCCESS;
    }
}
