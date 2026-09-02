<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * docs/03-rules.md §2.
 *
 *   BaseScore = 0.25F + 0.20M + 0.15V + 0.10H + 0.15U + 0.10D + 0.05B
 *
 * No API accepts M, H, D, B or BaseScore directly — they are computed here.
 */
class ScoreService
{
    public function __construct(
        private readonly ReferenceResolver $references,
        private readonly CoverageService $coverage,
    ) {}

    /**
     * @param  array  $need  the NeedEngine::compute() result
     * @return array{base_score:float,factors:array<string,float>,snapshot:array}
     */
    public function compute(Beneficiary $beneficiary, array $need, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ? Carbon::instance($asOf->toDateTime()) : Carbon::now();
        $weights = $this->references->weights($asOf);
        $w = $weights['values'];

        $monthlyNeed = max(1, (int) $need['monthly_need']);
        $members = $beneficiary->members()->get();
        $housing = $beneficiary->housing()->first();
        $health = $beneficiary->healthRecords()->get();
        $stableIncome = (int) $need['stable_income'];

        $f = (float) $need['f'];
        $m = $this->health($health, $monthlyNeed, $w);
        $v = $this->vulnerability($members, $w);
        $h = $this->housing($housing, $members->count(), $stableIncome, $need, $w);
        $u = $this->urgency($beneficiary, $asOf);
        $d = $this->deprivation($beneficiary, $monthlyNeed);
        $b = $this->debt($beneficiary, $monthlyNeed);

        $factors = compact('f', 'm', 'v', 'h', 'u', 'd', 'b');

        $base = $w['F'] * $f + $w['M'] * $m + $w['V'] * $v + $w['H'] * $h
              + $w['U'] * $u + $w['D'] * $d + $w['B'] * $b;

        return [
            'base_score' => round(min(100, max(0, $base)), 2),
            'factors' => array_map(fn ($x) => round($x, 4), array_change_key_case($factors, CASE_UPPER)),
            'snapshot' => ['weights' => $w, 'weight_versions' => $weights['versions']],
        ];
    }

    /**
     * M = 0.45×severity + 0.25×economic_impact + 0.15×care_burden + 0.15×cost_burden
     *
     * A household may hold several health records; the worst band of each kind is
     * taken and medical costs are summed. Logged in docs/07-decisions.md.
     */
    private function health($records, int $monthlyNeed, array $w): float
    {
        if ($records->isEmpty()) {
            return 0.0;
        }

        $severity = (float) $records->max('severity_band');
        $economic = (float) $records->max('economic_impact_band');
        $care = (float) $records->max('care_burden_band');
        $cost = (int) $records->sum('monthly_medical_cost');
        $costBurden = min(100, 100 * $cost / $monthlyNeed);

        return $w['M_severity'] * $severity
            + $w['M_economic_impact'] * $economic
            + $w['M_care_burden'] * $care
            + $w['M_cost_burden'] * $costBurden;
    }

    /** V = min(100, 60×dependents_ratio + 15×single_caregiver + 15×orphans + 10×unsupported_elderly) */
    private function vulnerability($members, array $w): float
    {
        $total = $members->count();

        if ($total === 0) {
            return 0.0;
        }

        $dependentsRatio = $members->where('dependent', true)->count() / $total;

        $earners = $members->filter(fn ($m) => ! $m->dependent && ! $m->unable_to_earn);
        $singleCaregiver = ($earners->count() === 1 && $members->where('dependent', true)->count() > 0) ? 1 : 0;

        $orphanKeys = config('sanabel.orphan_relation_keys');
        $orphans = $members->contains(fn ($m) => in_array($m->relation, $orphanKeys, true)) ? 1 : 0;

        $unsupportedElderly = ($members->contains(fn ($m) => $m->person_class === 'elderly')
            && $earners->isEmpty()) ? 1 : 0;

        return min(100, $w['V_dependents'] * $dependentsRatio
            + $w['V_single_caregiver'] * $singleCaregiver
            + $w['V_orphans'] * $orphans
            + $w['V_unsupported_elderly'] * $unsupportedElderly);
    }

    /** H = 0.35×safety + 0.20×overcrowding + 0.15×services + 0.15×eviction + 0.15×rent_burden */
    private function housing($housing, int $familySize, int $income, array $need, array $w): float
    {
        if (! $housing) {
            return 0.0;
        }

        $overcrowding = self::overcrowdingBand($familySize, (int) $housing->habitable_rooms);
        $reference = (int) ($need['snapshot']['rent_reference']['amount'] ?? 0);
        $rentBurden = self::rentBurden((int) $housing->monthly_rent, $reference, $income);

        return $w['H_safety'] * $housing->safety_band
            + $w['H_overcrowding'] * $overcrowding
            + $w['H_services'] * $housing->services_band
            + $w['H_eviction'] * $housing->eviction_band
            + $w['H_rent_burden'] * $rentBurden;
    }

    /**
     * Persons per habitable room. Kitchen, bathroom and corridors are excluded
     * from the room count by definition — `habitable_rooms` holds only rooms
     * people sleep or live in.
     */
    public static function overcrowdingBand(int $persons, int $habitableRooms): int
    {
        if ($habitableRooms < 1) {
            return 100;
        }

        $ratio = $persons / $habitableRooms;

        return match (true) {
            $ratio <= 2 => 0,
            $ratio <= 3 => 25,
            $ratio <= 4 => 50,
            $ratio <= 5 => 75,
            default => 100,
        };
    }

    /** rent_burden = min(100, 100 × max(0, min(rent, reference) − 0.30×income) ÷ max(reference,1)) */
    public static function rentBurden(int $rent, int $reference, int $income): float
    {
        return min(100, 100 * max(0, min($rent, $reference) - 0.30 * $income) / max($reference, 1));
    }

    /** U: no deadline 0 · within 90d 25 · within 30d 50 · within 7d 75 · within 48h 100 */
    public static function urgencyBand(?CarbonInterface $deadline, ?CarbonInterface $now = null): int
    {
        if (! $deadline) {
            return 0;
        }

        $now = $now ?? Carbon::now();
        $hours = $now->diffInHours($deadline, false);

        return match (true) {
            $hours <= 48 => 100,          // includes an already-passed deadline
            $hours <= 24 * 7 => 75,
            $hours <= 24 * 30 => 50,
            $hours <= 24 * 90 => 25,
            default => 0,
        };
    }

    private function urgency(Beneficiary $beneficiary, CarbonInterface $asOf): float
    {
        return (float) self::urgencyBand($beneficiary->urgency_deadline_at, $asOf);
    }

    /** D = 100 × (1 − min(1, confirmed_support_90d ÷ essential_need_90d)) */
    private function deprivation(Beneficiary $beneficiary, int $monthlyNeed): float
    {
        $days = (int) Setting::value(
            'deprivation_window_days',
            config('sanabel.setting_defaults.deprivation_window_days')
        );

        $essential = max(1, (int) round($monthlyNeed * $days / 30));
        $confirmed = $this->coverage->confirmedSupportInWindow($beneficiary);

        return 100 * (1 - min(1, $confirmed / $essential));
    }

    /** B = 100 × min(1, documented_necessary_debt ÷ (3 × MonthlyNeed)) */
    private function debt(Beneficiary $beneficiary, int $monthlyNeed): float
    {
        return 100 * min(1, (int) $beneficiary->documented_debt / max(1, 3 * $monthlyNeed));
    }
}
