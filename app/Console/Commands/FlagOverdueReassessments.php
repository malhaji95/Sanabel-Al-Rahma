<?php

namespace App\Console\Commands;

use App\Services\CaseService;
use Illuminate\Console\Command;

/** docs/03-rules.md §10 — flag and demote. Existing support is never stopped. */
class FlagOverdueReassessments extends Command
{
    protected $signature = 'sanabel:flag-reassessments';

    protected $description = 'Flag cases past their reassessment date';

    public function handle(CaseService $cases): int
    {
        $flagged = $cases->flagOverdueReassessments();

        $this->info("Flagged {$flagged} case(s) for reassessment.");

        return self::SUCCESS;
    }
}
