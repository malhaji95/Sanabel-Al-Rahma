<?php

namespace App\Console\Commands;

use App\Services\OverdueTaskAlerts;
use Illuminate\Console\Command;

/**
 * T-240 — the working-day window the association set on 3 November, read
 * once a morning. A task still untouched past it wakes the desk that holds
 * it and tells the desk above.
 */
class ChaseOverdueTasks extends Command
{
    protected $signature = 'sanabel:chase-tasks';

    protected $description = 'Alert on tasks left untouched past their working-day window';

    public function handle(OverdueTaskAlerts $alerts): int
    {
        $sent = $alerts->run();

        $this->info("Alerted on {$sent} overdue task(s).");

        return self::SUCCESS;
    }
}
