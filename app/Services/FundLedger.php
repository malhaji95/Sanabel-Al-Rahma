<?php

namespace App\Services;

use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\FinancialPeriod;
use App\Models\Fund;
use Illuminate\Support\Carbon;

/*
 | T-324 — phase two, item 9: «السجلّ المالي».
 |
 | The money was all recorded and none of it was in one place. A verified
 | transfer is a row in `donations`, what it was earmarked for is a row in
 | `donation_allocations`, the payment it funded is a row in `disbursements`,
 | and the link between the last two is a row in `disbursement_fundings`.
 |
 | So «how much is in the zakat fund today» was a question somebody answered
 | with a calculator, and «how did it get to that» was a question nobody could
 | answer at all.
 |
 | This reads those four tables into one list per fund: every movement in date
 | order with a running balance. It reads; it writes nothing and stores
 | nothing, and it adds no table of its own — a ledger kept beside the money
 | is a second truth to keep in step with the first.
 */
class FundLedger
{
    /** No fund has this many movements; the cap is against a runaway page. */
    public const LIMIT = 500;

    /**
     * One fund's movements, oldest first, each with the balance after it.
     *
     * @return array<int,array{at: Carbon, kind: string, amount: int, balance: int, ref: ?string, subject: ?string, closed: bool}>
     */
    public function for(Fund $fund, ?Carbon $from = null, ?Carbon $until = null): array
    {
        $movements = array_merge(
            $this->moneyIn($fund, $from, $until),
            $this->moneyOut($fund, $from, $until),
        );

        usort($movements, fn ($a, $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        $movements = array_slice($movements, 0, self::LIMIT);

        $balance = 0;
        $closedMonths = [];

        foreach ($movements as $i => $movement) {
            $balance += $movement['amount'];
            $movements[$i]['balance'] = $balance;

            // Marked rather than hidden: a movement inside a closed month is
            // exactly what somebody checking a closed month came to read.
            $key = $movement['at']->format('Y-m');
            $closedMonths[$key] ??= FinancialPeriod::isClosedFor($movement['at']);
            $movements[$i]['closed'] = $closedMonths[$key];
        }

        return $movements;
    }

    /** The closing balance, which is the last running balance. */
    public function balance(Fund $fund, ?Carbon $until = null): int
    {
        $movements = $this->for($fund, null, $until);

        return $movements === [] ? 0 : (int) end($movements)['balance'];
    }

    /**
     * Verified money that came into this fund, and the reversals that took
     * some of it back out.
     *
     * A reversal is a donation of its own with `reversal_of_id` set (rule 5 —
     * verified money is never edited), so it belongs here as a negative
     * movement rather than as a correction to the row it reverses.
     *
     * @return array<int,array<string,mixed>>
     */
    private function moneyIn(Fund $fund, ?Carbon $from, ?Carbon $until): array
    {
        /*
         | `verified` and `reversed` both. A reversed donation arrived: it was
         | believed, allocated and counted, and what undid it is the reversal
         | row beside it. Reading only `verified` drops the arrival and keeps
         | the return, which is a ledger showing money leaving a fund it never
         | entered.
         */
        $query = Donation::withoutGlobalScopes()
            ->where('fund_id', $fund->getKey())
            ->whereIn('status', ['verified', 'reversed'])
            ->whereNotNull('verified_at');

        $this->between($query, 'verified_at', $from, $until);

        return $query->get(['id', 'amount', 'currency', 'transaction_ref', 'verified_at', 'reversal_of_id', 'status'])
            ->map(fn (Donation $donation) => [
                'at' => $donation->verified_at,
                'kind' => $donation->reversal_of_id ? 'reversal' : 'donation',
                'amount' => $donation->reversal_of_id
                    ? -1 * (int) $donation->amount
                    : (int) $donation->amount,
                'balance' => 0,
                'ref' => $donation->transaction_ref,
                'subject' => null,
                'closed' => false,
            ])
            ->all();
    }

    /**
     * Money that left this fund: executed payments, each counted by the part
     * of it this fund actually funded.
     *
     * A payment may draw on several donations, so the figure here is the
     * funding line's own amount and not the payment's total. Counting the
     * total against every fund that touched it is how a ledger comes to show
     * more money leaving than ever arrived.
     *
     * @return array<int,array<string,mixed>>
     */
    private function moneyOut(Fund $fund, ?Carbon $from, ?Carbon $until): array
    {
        $query = Disbursement::withoutGlobalScopes()
            ->where('status', 'executed')
            ->whereNotNull('executed_at')
            ->whereHas('fundings.allocation.donation', fn ($q) => $q->where('fund_id', $fund->getKey()))
            ->with(['fundings.allocation.donation:id,fund_id']);

        $this->between($query, 'executed_at', $from, $until);

        return $query->get()
            ->map(function (Disbursement $disbursement) use ($fund) {
                $share = $disbursement->fundings
                    ->filter(fn ($funding) => (int) $funding->allocation?->donation?->fund_id === (int) $fund->getKey())
                    ->sum('amount');

                return [
                    'at' => $disbursement->executed_at,
                    'kind' => 'disbursement',
                    'amount' => -1 * (int) $share,
                    'balance' => 0,
                    'ref' => $disbursement->transfer_ref,
                    'subject' => $disbursement->period?->format('Y-m'),
                    'closed' => false,
                ];
            })
            ->filter(fn (array $movement) => $movement['amount'] !== 0)
            ->values()
            ->all();
    }

    private function between($query, string $column, ?Carbon $from, ?Carbon $until): void
    {
        if ($from) {
            $query->where($column, '>=', $from->copy()->startOfDay());
        }

        if ($until) {
            $query->where($column, '<=', $until->copy()->endOfDay());
        }
    }
}
