<?php

namespace App\Services;

use App\Models\Beneficiary;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * docs/03-rules.md §1.
 *
 *   MonthlyNeed  = Σ region_rates[member.person_class]
 *                + Σ applicable adjustments
 *                + rent_reference (renting households only)
 *                + approved manual adjustments
 *   StableIncome = Σ incomes where is_stable
 *   Gap          = max(0, MonthlyNeed − StableIncome − Received)
 *   F            = 100 × max(0, MonthlyNeed − StableIncome) ÷ MonthlyNeed
 *
 * Every computation returns the snapshot of reference values and versions it
 * used, so editing config later never changes an existing assessment (rule 8).
 */
class NeedEngine
{
    public function __construct(
        private readonly ReferenceResolver $references,
        private readonly CoverageService $coverage,
    ) {}

    /**
     * @param  int  $manualAdjustment  approved manual adjustment, smallest unit
     * @return array{
     *     monthly_need:int, stable_income:int, received:int, gap:int, f:float,
     *     currency:string, snapshot:array
     * }
     */
    public function compute(Beneficiary $beneficiary, ?CarbonInterface $asOf = null, int $manualAdjustment = 0): array
    {
        $asOf = $asOf ? Carbon::instance($asOf->toDateTime()) : Carbon::now();
        $region = $beneficiary->region()->withoutGlobalScopes()->firstOrFail();

        $members = $beneficiary->members()->get();
        $housing = $beneficiary->housing()->first();
        $incomes = $beneficiary->incomes()->get();

        // Each member is counted exactly once, under exactly one person_class.
        $perClass = [];
        $rateSnapshot = [];
        $membersTotal = 0;

        foreach ($members as $member) {
            $class = $member->person_class;
            $perClass[$class] = ($perClass[$class] ?? 0) + 1;
        }

        foreach ($perClass as $class => $count) {
            $rate = $this->references->rate($region, $class, $asOf);
            $membersTotal += $rate['amount'] * $count;
            $rateSnapshot[$class] = $rate + ['count' => $count];
        }

        // Adjustments applicable to this household.
        $adjustmentKeys = $this->applicableAdjustmentKeys($beneficiary, $members, $housing);
        $adjustments = $this->references->adjustments($region, $adjustmentKeys, $asOf);
        $adjustmentsTotal = array_sum(array_column($adjustments, 'amount'));

        // Rent reference — renting households only.
        $isRenting = (bool) $housing?->isRenting();
        $rent = $isRenting
            ? $this->references->rentReference($region, $members->count(), $asOf)
            : ['amount' => 0, 'version' => null, 'id' => null, 'band' => null];

        $monthlyNeed = $membersTotal + $adjustmentsTotal + $rent['amount'] + $manualAdjustment;

        $stableIncome = (int) $incomes->where('is_stable', true)->sum('amount');
        $received = $this->coverage->confirmedSupport($beneficiary);

        $gap = max(0, $monthlyNeed - $stableIncome - $received);

        $f = $monthlyNeed > 0
            ? 100 * max(0, $monthlyNeed - $stableIncome) / $monthlyNeed
            : 0.0;

        return [
            'monthly_need' => (int) $monthlyNeed,
            'stable_income' => $stableIncome,
            'received' => $received,
            'gap' => (int) $gap,
            'f' => round($f, 4),
            'currency' => config('sanabel.currency'),
            'snapshot' => [
                'computed_at' => $asOf->toIso8601String(),
                'region_id' => $region->id,
                'rates' => $rateSnapshot,
                'adjustments' => $adjustments,
                'rent_reference' => $rent,
                'manual_adjustment' => $manualAdjustment,
                'is_renting' => $isRenting,
                'members_total' => $membersTotal,
                'adjustments_total' => $adjustmentsTotal,
            ],
        ];
    }

    /**
     * A specific medical bill is a separate request, not part of MonthlyNeed —
     * so health never appears here.
     *
     * @return array<int,string>
     */
    private function applicableAdjustmentKeys(Beneficiary $beneficiary, $members, $housing): array
    {
        $keys = [];

        if ($members->contains(fn ($m) => $m->person_class === 'child')) {
            $keys[] = 'children_present';
        }

        if ($members->contains(fn ($m) => $m->person_class === 'elderly')) {
            $keys[] = 'elderly_present';
        }

        if ($members->contains(fn ($m) => $m->unable_to_earn)) {
            $keys[] = 'unable_to_earn_present';
        }

        if ($housing && $housing->housing_type === 'shelter') {
            $keys[] = 'shelter_household';
        }

        return $keys;
    }
}
