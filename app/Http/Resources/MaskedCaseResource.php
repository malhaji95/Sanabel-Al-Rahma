<?php

namespace App\Http\Resources;

use App\Models\Beneficiary;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\RankingService;
use App\Services\ScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Rule 2 — donors are served exclusively by this class.
 *
 * Shown:       file number, area (not village), family size, need type,
 *              need amount, coverage %, urgency label.
 * Never shown: any name, national ID, phone, address, wallet, landlord,
 *              media, diagnosis, exact age, exact rent, raw score.
 *
 * The wallet a donor pays into is Sanabel Al-Rahma's own and the same for
 * every family, so it is a setting shown where payment happens. Of a
 * household's own account only the last four digits appear, by the
 * association's decision of 13 October, and only so a donor can tell that the
 * family they are funding is the family they meant: the transfer goes to the
 * association's fund, never to the family. `show_wallet_last4` closes it
 * again.
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
        'priority_band', 'priority_label', 'is_urgent', 'accepts_zakat', 'can_share',
        // T-78 — the funding target. Figures about the appeal, not about the
        // household: a share of a need the donor is already shown.
        'target_percent', 'target_amount', 'target_remaining', 'target_complete',
        'show_sufficiency_note',
        // T-82 — four digits of the household's account, and nothing more.
        'wallet_last4',
        // T-83 — which month these figures answer, and whether it is answered.
        'coverage_month', 'coverage_month_label', 'is_covered',
    ];

    /**
     * A list has already fetched confirmed support for every case in one query;
     * it passes the figure in so the card does not go and ask again, together
     * with the month that figure belongs to.
     *
     * Without a month the card answers for the month a donor would be funding
     * now — this one, or the next once this one is covered and the next has
     * opened (T-83). A basket passes the month the donor actually pledged for.
     */
    public function __construct(
        $resource,
        private readonly ?int $confirmed = null,
        private readonly ?Carbon $month = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var Beneficiary $case */
        $case = $this->resource;

        $coverage = app(CoverageService::class);
        $ranking = app(RankingService::class);

        // The relation, not a fresh query: a list eager-loads it once for the page.
        $members = $case->members;

        /*
         | T-83 — every figure below answers one month, and the card says which.
         | A family covered for this month and open for the next is funded for
         | the next, so the month is settled before anything is counted.
         |
         | A list passes both in; on its own the card asks for them together,
         | rather than walking the open months once for the month and again for
         | the money.
         */
        if ($this->month === null) {
            $stage = $coverage->fundingStage($case);
            $month = $stage['month'];
            // One figure for the three coverage numbers on the card.
            $confirmed = $this->confirmed ?? $stage['funded'];
        } else {
            $month = $this->month;
            $confirmed = $this->confirmed ?? $coverage->fundedForMonth($case, $month);
        }

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
            'coverage_percent' => $coverage->coveragePercent($case, $confirmed, $month),
            'coverage_label' => __('sanabel.coordination.coverage_'.$coverage->coverageLabel($case, $confirmed, $month)),
            'remaining_amount' => $coverage->remainingNeed($case, $confirmed, $month),
            // The badge the association asked for: priority read from how much
            // of the need is still uncovered.
            'priority_band' => $band = $coverage->priorityBand($case, $confirmed, $month),
            'priority_label' => __('sanabel.masked.priority.'.$band),
            // Kept apart from the band on purpose. A case can be nearly covered
            // and still be running out of time; one badge cannot say both.
            'is_urgent' => ScoreService::urgencyBand($case->urgency_deadline_at) >= 75,
            'urgency_label' => $this->urgencyLabel($case),
            'has_chronic_illness' => $this->hasChronicIllness($case),
            'rent_band' => $this->rentBand($case),
            'is_renting' => (bool) $case->housing?->isRenting(),
            'waiting_weeks' => $ranking->waitingBonus($case),
            // Whether zakat may be paid on this file — never which of the eight
            // categories it falls under. The category describes the household,
            // and rule 2 keeps that behind the association's door.
            'accepts_zakat' => filled($case->zakat_category),
            // Not a fact about the household: the association's own switch for
            // whether this file's link may be passed around.
            'can_share' => (bool) $case->is_shareable,

            /*
             | T-78 — the stage the association is funding now. The real need is
             | still `need_amount` beside it, deliberately: a family at 100% of
             | a 30% target would otherwise read as covered when it is short
             | seven tenths of what it needs.
             */
            'target_percent' => $coverage->targetPercent($case),
            'target_amount' => $coverage->targetAmount($case),
            'target_remaining' => $coverage->targetRemaining($case, $confirmed, $month),
            'target_complete' => $coverage->targetIsComplete($case, $confirmed, $month),
            'show_sufficiency_note' => (bool) Setting::value(
                'show_sufficiency_note',
                config('sanabel.setting_defaults.show_sufficiency_note')
            ),

            'wallet_last4' => $this->walletLast4($case),

            /*
             | T-83 — the month on the card. A donation answers one month and
             | no other, so a donor who is told «تغطية شهر …» is being told
             | what their money does; and a month already answered says so
             | instead of disappearing, which is what the association asked for
             | on 13 October.
             */
            'coverage_month' => $month->format('Y-m'),
            'coverage_month_label' => $month->translatedFormat('F Y'),
            'is_covered' => $coverage->monthIsCovered($case, $month, $confirmed),
        ];
    }

    /**
     * The last four digits of the household's account, or nothing.
     *
     * Digits only, and only when there are at least four of them: an
     * anonymised file carries a marker rather than a number, and a short value
     * would otherwise be handed over whole.
     */
    private function walletLast4(Beneficiary $case): ?string
    {
        $on = (bool) Setting::value(
            'show_wallet_last4',
            config('sanabel.setting_defaults.show_wallet_last4')
        );

        if (! $on || $case->isAnonymised()) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $case->wallet_encrypted);

        return strlen((string) $digits) >= 4 ? substr((string) $digits, -4) : null;
    }

    /** Counts per band only — never an exact age, and never a child's details. */
    private function ageBands($members): array
    {
        return [
            'child' => $members->where('person_class', 'child')->count(),
            'adult' => $members->where('person_class', 'adult')->count(),
            'wife' => $members->where('person_class', 'wife')->count(),
            'elderly' => $members->where('person_class', 'elderly')->count(),
        ];
    }

    /** Whether anyone in the household has a recorded chronic condition — never which. */
    private function hasChronicIllness(Beneficiary $case): bool
    {
        if ($case->relationLoaded('healthRecords')) {
            return $case->healthRecords->where('severity_band', '>', 0)->isNotEmpty();
        }

        return $case->healthRecords()->where('severity_band', '>', 0)->exists();
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
