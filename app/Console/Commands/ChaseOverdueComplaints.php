<?php

namespace App\Console\Commands;

use App\Services\ComplaintEscalation;
use Illuminate\Console\Command;

/**
 * T-320 — the complaints desk's working-day window, read once a morning. A
 * complaint still waiting past it wakes the desk that holds it and tells the
 * desk above.
 */
class ChaseOverdueComplaints extends Command
{
    protected $signature = 'sanabel:chase-complaints';

    protected $description = 'Alert on complaints left unanswered past their working-day window';

    public function handle(ComplaintEscalation $escalation): int
    {
        $sent = $escalation->run();

        $this->info("Alerted on {$sent} overdue complaint(s).");

        return self::SUCCESS;
    }
}
