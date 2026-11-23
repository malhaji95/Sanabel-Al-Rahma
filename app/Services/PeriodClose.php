<?php

namespace App\Services;

use App\Models\FinancialPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/*
 | T-323 — phase two, item 9: «الإقفال».
 |
 | Nothing stopped a payment being raised for a month whose books had been
 | gone over and reported. So a figure the association had put its name to
 | could move after it had been read, and the report and the ledger would not
 | agree, with no row anywhere saying which came first.
 |
 | Closing is not a lock on the past. Every payment already raised stays as it
 | is, every order in flight finishes its path, and nothing is recomputed.
 | What a closed month refuses is the new.
 */
class PeriodClose
{
    /** The key the accountant holds: whoever reconciles the books closes them. */
    public const KEY = 'reconcile_disbursement';

    public function __construct(private readonly PermissionService $permissions) {}

    public function close(Carbon $period, User $actor): FinancialPeriod
    {
        $period = $period->copy()->startOfMonth();

        $this->mayDecide($actor);

        /*
         | A month that has not ended is a month money is still arriving in.
         | Closing it would refuse payments the association has not yet made
         | and fully means to.
         */
        if ($period->gte(Carbon::today()->startOfMonth())) {
            throw new RuntimeException(__('sanabel.period.not_ended'));
        }

        $row = FinancialPeriod::firstOrNew(['period' => $period->toDateString()]);

        if ($row->exists && $row->isClosed()) {
            throw new RuntimeException(__('sanabel.period.already_closed'));
        }

        $row->forceFill([
            'period' => $period->toDateString(),
            'closed_by' => $actor->getKey(),
            'closed_at' => now(),
        ])->save();

        return $row->refresh();
    }

    /**
     * Reopening, with the reason on the row.
     *
     * The closing is not erased: both stamps stay, and which is the later one
     * is what decides the month's state. An auditor reading this row sees
     * that it was closed, when it was reopened, and why.
     */
    public function reopen(Carbon $period, User $actor, string $reason): FinancialPeriod
    {
        $period = $period->copy()->startOfMonth();

        $this->mayDecide($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException(__('sanabel.period.reopen_reason_required'));
        }

        $row = FinancialPeriod::query()->whereDate('period', $period)->first();

        if (! $row || ! $row->isClosed()) {
            throw new RuntimeException(__('sanabel.period.not_closed'));
        }

        $row->forceFill([
            'reopened_by' => $actor->getKey(),
            'reopened_at' => now(),
            'reopen_reason_ar' => $reason,
        ])->save();

        return $row->refresh();
    }

    /** The guard every money path calls before it writes into a month. */
    public function refuseIfClosed(Carbon $period): void
    {
        if (FinancialPeriod::isClosedFor($period)) {
            throw new RuntimeException(__('sanabel.period.closed', [
                'period' => $period->copy()->startOfMonth()->format('Y-m'),
            ]));
        }
    }

    private function mayDecide(User $actor): void
    {
        if (! $this->permissions->has($actor, self::KEY)) {
            throw new RuntimeException(__('sanabel.period.not_permitted'));
        }
    }
}
