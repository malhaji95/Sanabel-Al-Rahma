<?php

namespace App\Services;

use App\Exceptions\HouseholdIncomplete;
use App\Exceptions\ReferenceValueMissing;
use App\Models\Asset;
use App\Models\Beneficiary;
use App\Models\Income;
use App\Models\Livestock;
use App\Models\NeedAddition;
use App\Models\Scopes\RegionScope;
use App\Models\SupportSource;
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
        $region = $beneficiary->region()->withoutGlobalScope(RegionScope::class)->firstOrFail();

        $members = $beneficiary->members()->get();
        $housing = $beneficiary->housing()->first();
        $incomes = $beneficiary->incomes()->get();

        /*
         | T-284 — «لاحظنا في إحدى التجارب أن مقدم الطلب، وهي أم أرملة، لم
         | يظهر ضمن حساب الاحتياج», 10 November. They were right.
         |
         | The engine counted the rows of `household_members` and nothing
         | else, and the applicant is not one of those rows: they are the file
         | itself. So a widowed mother with two children was priced as two
         | children, and the association was told to send a household of three
         | what a household of two costs. Nothing anywhere said so.
         |
         | The applicant is a person and the file already knows their age, so
         | they are counted here from the file, once. A member row entered
         | under «رب الأسرة» is the same person written twice and is skipped,
         | which is the other half of the same bug.
         |
         | And an applicant with no date of birth has no class, so there is no
         | honest figure to compute: it is named and refused, exactly as a
         | missing reference value is.
         */
        $perClass = [];
        $rateSnapshot = [];
        $membersTotal = 0;
        $incomplete = [];

        if ($beneficiary->birth_date === null) {
            $incomplete[] = __('sanabel.beneficiary.birth_date');
        } else {
            $applicantClass = DependencyRules::personClass(
                (int) $beneficiary->birth_date->diffInYears(now()),
                'head',
            );

            $perClass[$applicantClass] = 1;
        }

        if ($incomplete !== []) {
            throw new HouseholdIncomplete($incomplete);
        }

        foreach ($members as $member) {
            // The applicant, written a second time as a member row.
            if ($member->relation === 'head') {
                continue;
            }

            $class = $member->person_class;
            $perClass[$class] = ($perClass[$class] ?? 0) + 1;
        }

        // A reference the association has not approved is null, never zero.
        // Anything missing is collected and the assessment is refused below,
        // rather than quietly computing a need on a number nobody chose.
        $missing = [];

        foreach ($perClass as $class => $count) {
            $rate = $this->references->rate($region, $class, $asOf);

            if ($rate['amount'] === null) {
                $missing[] = __('sanabel.person_class.'.$class);

                continue;
            }

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
            // T-284 — the whole household, the applicant included. This
            // read `$members->count()` and put a family of five in the band
            // of four.
            ? $this->references->rentReference($region, $beneficiary->householdSize(), $asOf)
            : ['amount' => 0, 'version' => null, 'id' => null, 'band' => null];

        if ($isRenting && $rent['amount'] === null) {
            $missing[] = __('sanabel.reference.rent_for_band', ['band' => $rent['band']]);
        }

        if ($missing !== []) {
            throw new ReferenceValueMissing($missing, $region->name_ar);
        }

        /*
         | T-289 — amounts the association approved towards a named cost, for
         | the months they approved them for. Never derived from the medical
         | cost or the debt on the file: those raise the score and add no
         | money, which is the separation the association asked for. These
         | exist only because somebody decided they should.
         */
        $additions = $beneficiary->needAdditions()->inForce($asOf)->with('coveringPayments')->get();

        /*
         | T-304 — «منع تمويل المصروف نفسه مرتين», 12 November.
         |
         | An item is funded for what is left of it after another body has
         | answered part or all of it. Sanabel approving an amount towards a
         | course of treatment, and another association paying for that same
         | treatment, used to be two facts in two tables that did not know
         | about each other — so the money was raised twice and nothing
         | noticed.
         */
        $additionsTotal = (int) $additions->sum(fn (NeedAddition $a) => $a->netAmount($asOf));

        $monthlyNeed = $membersTotal + $adjustmentsTotal + $rent['amount']
            + $additionsTotal + $manualAdjustment;

        /*
         | T-303 — «ربط جميع مصادر الدخل بمحرك الاحتساب تلقائيًا», and «عدم
         | اشتراط إدخال المصدر مرتين في شاشتين مختلفتين», 12 November.
         |
         | Each source is read where the researcher wrote it, once. None is
         | mirrored into an `incomes` row, so no two of them can drift apart
         | and none has to be typed twice to be counted.
         |
         | The animals: a steady income was counted and a seasonal one lost.
         | It is averaged now, by the same rule as every other seasonal sum.
         */
        $livestockIncome = (int) $beneficiary->livestock()->get()
            ->sum(fn (Livestock $l) => $l->monthlyContribution());

        /*
         | The rent of a plot, a house or a vehicle. The assets table recorded
         | what a thing was worth and never what it brought in, and the engine
         | did not read it at all.
         */
        $assetIncome = (int) $beneficiary->assets()->get()
            ->sum(fn (Asset $a) => $a->monthlyContribution());

        /*
         | The standing arrangements: neighbours, relatives, a daughter's
         | husband, another association. Recorded, and reaching nothing —
         | neither income nor coverage — because the table they live in was
         | built for a payment against a named month.
         |
         | A one-off payment is still coverage and is still deducted from the
         | month it answers, over in `externalForMonth`. The two are exclusive
         | by construction, which is how the same help is not deducted twice.
         */
        $supportIncome = (int) $beneficiary->supportSources()->get()
            ->sum(fn (SupportSource $s) => $s->monthlyContribution());

        /*
         | T-221 — «ينجمع تلقائياً», 1 November, choosing (ب) of the two we
         | put to them: the figures written on the people are summed into the
         | household's income by the engine, and the income step stops being
         | the place those same figures are typed a second time.
         |
         | T-121 recorded what a person's work pays and deliberately kept it
         | out of the score, because the same money was also written as an
         | `incomes` row and counting both would count it twice. So the
         | researcher told the engine a salary twice or not at all, and had
         | to know which.
         |
         | The three places a person's own earnings are written: the head of
         | the file, the spouse, and each member of the household. Read here
         | rather than mirrored into an `incomes` row, exactly as livestock
         | income is, so the two can never drift apart.
         |
         | What this does not do is tell a work row typed by hand from any
         | other row — `source_type` is free text. The guard against the
         | double count is the income step itself, which no longer asks for
         | those rows and shows the researcher the figure already counted.
         */
        $personWorkIncome = (int) $beneficiary->work_income
            + (int) $beneficiary->spouse_work_income
            + (int) $members->sum('work_income');

        /*
         | T-287 — each row says what it puts into a month: the amount if it
         | is steady, its yearly total over twelve if it is seasonal and
         | counted on, and nothing if it is neither.
         */
        $stableIncome = (int) $incomes->sum(fn (Income $income) => $income->monthlyContribution())
            + $livestockIncome
            + $assetIncome
            + $supportIncome
            + $personWorkIncome;
        // This month's money against this month's need (approved 24 Sep 2026).
        // A payment another body made for the same month counts here and only
        // here: approved 11 October, it answers the need like a partial
        // donation, and it is never income, so it leaves `stable_income` and
        // therefore `f` untouched.
        $external = $this->coverage->externalForMonth($beneficiary, $asOf);
        $received = $this->coverage->confirmedForMonth($beneficiary) + $external;

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
                // Each one on its own line, so the breakdown can say what the
                // money was for and who approved it (rule 8: frozen here).
                'need_additions' => $additions->map(fn (NeedAddition $a) => [
                    'id' => $a->getKey(),
                    'kind' => $a->kind,
                    'amount' => (int) $a->amount,
                    // T-304 — what another body answered of it, what is left
                    // for the association, and which of the three states that
                    // makes it. Frozen with the study like everything else
                    // here, so an old one still says why it asked for what it
                    // asked for (rule 8).
                    'covered' => $a->coveredAmount($asOf),
                    'net' => $a->netAmount($asOf),
                    'coverage_state' => $a->coverageState($asOf),
                    'covered_by_health_path' => (bool) $a->covered_by_health_path,
                    'reason_ar' => $a->reason_ar,
                    'approved_by' => $a->approver?->name,
                    'approved_at' => $a->approved_at?->toIso8601String(),
                ])->values()->all(),
                'need_additions_total' => $additionsTotal,
                'is_renting' => $isRenting,
                'members_total' => $membersTotal,
                'adjustments_total' => $adjustmentsTotal,
                'external_support' => $external,
                'livestock_income' => $livestockIncome,
                // T-303 — written beside the rest, so an old study still says
                // where each part of its income came from (rule 8).
                'asset_income' => $assetIncome,
                'support_income' => $supportIncome,
                // T-221 — written down beside the rest, so an old assessment
                // still says where its income figure came from (rule 8).
                'person_work_income' => $personWorkIncome,
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
        /*
         | T-301 — «احتسابه في البدلات الإضافية وعوامل الهشاشة عند انطباق
         | شروطها», 12 November.
         |
         | These three were read off the member rows alone, and the applicant
         | is not one of those rows. So a grandmother of seventy keeping two
         | grandchildren opened no elderly item: the only elderly person in
         | the house was the one holding the file. The household is read as
         | people now, and the applicant is one of them.
         */
        $people = $beneficiary->people();
        $keys = [];

        if ($people->contains(fn ($p) => $p->person_class === 'child')) {
            $keys[] = 'children_present';
        }

        if ($people->contains(fn ($p) => $p->person_class === 'elderly')) {
            $keys[] = 'elderly_present';
        }

        if ($people->contains(fn ($p) => $p->unable_to_earn)) {
            $keys[] = 'unable_to_earn_present';
        }

        if ($housing && $housing->housing_type === 'shelter') {
            $keys[] = 'shelter_household';
        }

        return $keys;
    }
}
