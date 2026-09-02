<?php

namespace App\Http\Resources;

use App\Models\Beneficiary;
use App\Services\CoverageService;
use App\Services\RankingService;
use App\Services\ScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rule 2 — donors are served exclusively by this class.
 *
 * Shown:       file number, area (not village), family size, need type,
 *              need amount, coverage %, urgency label.
 * Never shown: any name, national ID, phone, address, wallet, landlord,
 *              media, diagnosis, exact age, exact rent, raw score.
 *
 * Age becomes a band, illness becomes "chronic illness", rent becomes a band.
 * Nothing identifying a child is ever published.
 *
 * @mixin Beneficiary
 */
class MaskedCaseResource extends JsonResource
{
    /**
     * Every key this resource is ever allowed to emit. The leak test asserts
     * that a response carries nothing outside this list.
     */
    public const ALLOWED_KEYS = [
        'file_number', 'area_ar', 'family_size', 'age_bands', 'need_type', 'need_type_label',
        'need_amount', 'currency', 'coverage_percent', 'coverage_label', 'remaining_amount',
        'urgency_label', 'has_chronic_illness', 'rent_band', 'is_renting', 'waiting_weeks',
    ];

    public function toArray(Request $request): array
    {
        /** @var Beneficiary $case */
        $case = $this->resource;

        $coverage = app(CoverageService::class);
        $ranking = app(RankingService::class);

        $members = $case->members()->get();

        return [
            'file_number' => $case->file_number,
            // Area, never the village — a village plus a family size identifies a household.
            'area_ar' => $case->region?->ancestorOfType('area')?->name_ar
                ?? $case->region?->ancestorOfType('governorate')?->name_ar,
            'family_size' => $members->count(),
            'age_bands' => $this->ageBands($members),
            'need_type' => $case->support_type,
            'need_type_label' => __('sanabel.masked.need_type.'.$case->support_type),
            'need_amount' => $coverage->needAmount($case),
            'currency' => config('sanabel.currency'),
            'coverage_percent' => $coverage->coveragePercent($case),
            'coverage_label' => __('sanabel.coordination.coverage_'.$coverage->coverageLabel($case)),
            'remaining_amount' => $coverage->remainingNeed($case),
            'urgency_label' => $this->urgencyLabel($case),
            'has_chronic_illness' => $case->healthRecords()->where('severity_band', '>', 0)->exists(),
            'rent_band' => $this->rentBand($case),
            'is_renting' => (bool) $case->housing?->isRenting(),
            'waiting_weeks' => $ranking->waitingBonus($case),
        ];
    }

    /** Counts per band only — never an exact age, and never a child's details. */
    private function ageBands($members): array
    {
        return [
            'child' => $members->where('person_class', 'child')->count(),
            'adult' => $members->where('person_class', 'adult')->count(),
            'elderly' => $members->where('person_class', 'elderly')->count(),
        ];
    }

    /** A label, never the raw score. */
    private function urgencyLabel(Beneficiary $case): string
    {
        $band = ScoreService::urgencyBand($case->urgency_deadline_at);

        return __('sanabel.masked.urgency.'.match (true) {
            $band >= 100 => 'critical',
            $band >= 75 => 'high',
            $band >= 50 => 'medium',
            $band >= 25 => 'low',
            default => 'none',
        });
    }

    /** A band, never the exact rent. */
    private function rentBand(Beneficiary $case): string
    {
        $housing = $case->housing;

        if (! $housing || ! $housing->isRenting() || $housing->monthly_rent <= 0) {
            return __('sanabel.masked.rent_band.none');
        }

        $reference = max(1, (int) ($case->currentAssessment()?->snapshot_json['rent_reference']['amount'] ?? 0));
        $ratio = $housing->monthly_rent / $reference;

        return __('sanabel.masked.rent_band.'.match (true) {
            $ratio < 0.75 => 'low',
            $ratio <= 1.25 => 'medium',
            default => 'high',
        });
    }
}
