<?php

namespace App\Http\Resources;

use App\Models\Beneficiary;
use App\Models\HealthRecord;
use App\Models\Setting;
use App\Services\CoverageService;
use App\Services\RankingService;
use App\Services\ScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rule 2 — donors are served exclusively by this class.
 *
 * Shown:       file number, area (not village), family size, need type,
 *              need amount, coverage %, urgency label — and, since T-101 and
 *              the association's list of 15 October, the household itself:
 *              male or female, marital status, smoking, dependants, the
 *              house, the debt total, the illnesses named from a list, what
 *              is owned, and a row per dependant.
 * Never shown: any name, national ID, phone, address, wallet, landlord,
 *              media, exact age, exact rent, raw score — nor any free text
 *              whatever, which is what makes the T-101 fields publishable:
 *              `description_ar`, `furnishing_ar`, `situation_ar`,
 *              `details_ar`, `kind_other_ar`, `notes_ar` and a creditor's own
 *              particulars are all the researcher writing to the committee,
 *              and not one of them is a list or a band.
 *
 * The wallet a donor pays into is Sanabel Al-Wafa's own and the same for
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
        /*
         | T-101 — the fields the association named on 15 October. Counts,
         | bands and labels out of a list; not one of them is free text, and
         | each group is closed again by its own switch.
         */
        'applicant_gender_label', 'marital_status_label', 'smokes', 'dependents_count',
        'housing_type_label', 'rooms', 'bathrooms', 'has_kitchen',
        'home_safety_label', 'home_services_label',
        'has_debt', 'debt_amount',
        'conditions', 'ongoing_treatment',
        'has_vehicle', 'vehicles', 'has_livestock', 'livestock', 'has_property', 'property',
        'dependents',
        // T-106 — the paragraph, and the heading the association chose for it.
        'story_ar', 'story_title',
    ];

    /**
     * T-101 — every key one row of `dependents` is allowed to carry.
     *
     * A second allowlist rather than a comment: the rows are the one nested
     * shape on this resource, and the leak test checks them against this the
     * way it checks the payload against ALLOWED_KEYS. A name, a birth year or
     * a national ID added to a row would otherwise pass unseen under a key
     * that is itself allowed.
     */
    public const DEPENDENT_KEYS = [
        'relation_label', 'gender_label', 'is_under_15', 'is_orphan',
        'marital_label', 'occupation_label', 'has_condition', 'conditions',
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

            /*
             | T-101 — the household as the association asked it be described
             | on 15 October, in six groups with six switches. Each group is
             | emitted whether it is open or closed — a key that disappears
             | would move the leak test's allowlist from «these and no others»
             | to «whatever the settings happen to say today».
             */
            ...$this->profile($case),
            ...$this->housingDetail($case),
            ...$this->debt($case),
            'conditions' => $this->conditions($case->healthRecords->whereNull('member_id')),
            /*
             | T-102 — «علاج دائم», asked for on 15 October beside the illness.
             | It is the one health answer that changes what a donation is
             | for: a condition that is being treated every month is a
             | recurring cost, and a donor reading «سكري» alone cannot tell
             | the difference.
             */
            'ongoing_treatment' => $this->ongoingTreatment($case),
            ...$this->possessions($case),
            'dependents' => $this->dependents($case, $members),

            /*
             | T-106 — «وضروري شرح». The only free text a donor ever reads,
             | and the only one written for them: approved by a person who
             | may approve a file, and unapproved again by any edit to it.
             */
            'story_ar' => $this->story($case),
            'story_title' => __('sanabel.masked.story_titles.'.Setting::value(
                'story_title',
                config('sanabel.setting_defaults.story_title')
            )),
        ];
    }

    /** Whether one of the association's donor-disclosure switches is open. */
    private function switched(string $key): bool
    {
        return (bool) Setting::value($key, config('sanabel.setting_defaults.'.$key));
    }

    /**
     * Who the applicant is, in the four answers the association asked for:
     * male or female, marital status, whether they smoke, and how many people
     * depend on them.
     *
     * The marital status reads in the gender of the person it describes —
     * «أرملة» for a woman and «أرمل» for a man — because most of these files
     * are a woman's and the association wrote it that way in their own list.
     */
    private function profile(Beneficiary $case): array
    {
        if (! $this->switched('show_household_profile')) {
            return [
                'applicant_gender_label' => null,
                'marital_status_label' => null,
                'smokes' => null,
                'dependents_count' => null,
            ];
        }

        return [
            'applicant_gender_label' => $case->gender
                ? __('sanabel.gender.'.$case->gender)
                : null,
            'marital_status_label' => $case->marital_status
                ? __('sanabel.'.($case->gender === 'female' ? 'marital_status_f' : 'marital_status').'.'.$case->marital_status)
                : null,
            'smokes' => $case->smokes,
            'dependents_count' => $case->members->where('dependent', true)->count(),
        ];
    }

    /**
     * The house: owned, rented or lent, how many rooms and bathrooms, whether
     * there is a kitchen, and the state of the building.
     *
     * The state is the two bands the researcher already grades, safety and
     * services, and not `furnishing_ar` beside them, which is a sentence.
     */
    private function housingDetail(Beneficiary $case): array
    {
        $housing = $case->housing;

        if (! $this->switched('show_housing_detail') || ! $housing) {
            return [
                'housing_type_label' => null,
                'rooms' => null,
                'bathrooms' => null,
                'has_kitchen' => null,
                'home_safety_label' => null,
                'home_services_label' => null,
            ];
        }

        return [
            'housing_type_label' => $housing->housing_type
                ? __('sanabel.housing_type.'.$housing->housing_type)
                : null,
            'rooms' => $housing->habitable_rooms,
            'bathrooms' => $housing->bathrooms,
            'has_kitchen' => $housing->has_kitchen,
            'home_safety_label' => __('sanabel.bands.safety.'.(int) $housing->safety_band),
            'home_services_label' => __('sanabel.bands.services.'.(int) $housing->services_band),
        ];
    }

    /**
     * Whether the household owes money, and how much.
     *
     * The figure is `documented_debt`, which is the committee's own verified
     * total and the one the score reads — never the rows behind it, because a
     * debt row carries the creditor's name, address and telephone number.
     */
    private function debt(Beneficiary $case): array
    {
        if (! $this->switched('show_debt_amount')) {
            return ['has_debt' => null, 'debt_amount' => null];
        }

        $amount = (int) $case->documented_debt;

        return [
            'has_debt' => $amount > 0,
            'debt_amount' => $amount > 0 ? $amount : null,
        ];
    }

    /**
     * The illnesses named, as labels out of the list the researcher chooses
     * from — never `description_ar`, which is the paragraph written for the
     * committee and the one field on this file nobody wrote for a donor.
     *
     * @param  Collection<int,HealthRecord>  $records
     * @return array<int,string>
     */
    private function conditions($records): array
    {
        if (! $this->switched('show_health_condition')) {
            return [];
        }

        return $records
            ->pluck('condition')
            ->filter()
            ->unique()
            ->map(fn (string $condition) => __('sanabel.health.conditions.'.$condition))
            ->values()
            ->all();
    }

    /** Whether anyone in the household is under continuing treatment. */
    private function ongoingTreatment(Beneficiary $case): ?bool
    {
        if (! $this->switched('show_health_condition')) {
            return null;
        }

        return $case->healthRecords->where('ongoing_treatment', true)->isNotEmpty();
    }

    /**
     * What the household owns: a vehicle, livestock, other property.
     *
     * «ذكرها» is answered out of the kind column, which is a list — a car, a
     * tractor, a plot, sheep and how many head. `kind_other_ar` and
     * `details_ar` beside them are the researcher's own words for the
     * committee, and stay there.
     */
    private function possessions(Beneficiary $case): array
    {
        if (! $this->switched('show_possessions')) {
            return [
                'has_vehicle' => null, 'vehicles' => [],
                'has_livestock' => null, 'livestock' => [],
                'has_property' => null, 'property' => [],
            ];
        }

        $assets = $case->assets->groupBy(fn ($asset) => in_array($asset->kind, self::VEHICLE_KINDS, true)
            ? 'vehicle'
            : 'property');

        $label = fn ($rows) => collect($rows)
            ->pluck('kind')
            ->filter()
            ->unique()
            ->map(fn (string $kind) => __('sanabel.asset.kinds.'.$kind))
            ->values()
            ->all();

        $livestock = $case->livestock
            ->map(fn ($head) => [
                'label' => __('sanabel.livestock.kinds.'.$head->kind),
                'count' => (int) $head->head_count,
            ])
            ->values()
            ->all();

        return [
            'has_vehicle' => $assets->has('vehicle'),
            'vehicles' => $label($assets->get('vehicle', [])),
            'has_livestock' => $livestock !== [],
            'livestock' => $livestock,
            'has_property' => $assets->has('property'),
            'property' => $label($assets->get('property', [])),
        ];
    }

    /**
     * One row per dependant, in the five answers the association asked for on
     * 15 October: a boy or a girl, under fifteen or not, orphaned, what they
     * do, and any illness.
     *
     * No name, no birth year, no exact age: a roster of ages beside an area
     * and a family size is a household, and the age is therefore the one
     * question the association actually asked — whether they are under
     * fifteen.
     *
     * The orphan answer is theirs too, and conditional in their own words —
     * «يتيم اذا كانت ارملة». A child is not described as orphaned on a file
     * where the father is alive and on it.
     */
    private function dependents(Beneficiary $case, $members): array
    {
        if (! $this->switched('show_dependents')) {
            return [];
        }

        $widowed = $case->marital_status === 'widowed';

        return $members
            ->where('dependent', true)
            ->map(function ($member) use ($case, $widowed) {
                $theirs = $case->healthRecords->where('member_id', $member->id);

                return [
                    // T-106 — «صلة القرابة: ابن، بنت، أخ، أخت، وغيرهم».
                    'relation_label' => $member->relation
                        ? __('sanabel.relation.'.$member->relation)
                        : null,
                    'gender_label' => $member->gender ? __('sanabel.gender.'.$member->gender) : null,
                    'is_under_15' => $member->birth_year ? $member->age() < 15 : null,
                    'is_orphan' => $widowed ? (bool) $member->is_orphan : false,
                    'marital_label' => $member->marital_status
                        ? __('sanabel.'.($member->gender === 'female' ? 'marital_status_f' : 'marital_status').'.'.$member->marital_status)
                        : null,
                    'occupation_label' => $this->occupation($member),
                    // That there is one, which is a different question from
                    // what it is called: the naming has its own switch.
                    'has_condition' => $this->switched('show_health_condition')
                        ? $theirs->where('severity_band', '>', 0)->isNotEmpty()
                        : null,
                    'conditions' => $this->conditions($theirs),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * «الصفة»: student, retired, employee, and the rest of the list the
     * survey offers.
     *
     * T-106 — the association asked for it by name on 16 October. Older rows
     * answered the same question with two booleans instead, so those are
     * read when the column itself is empty rather than left blank.
     */
    private function occupation($member): ?string
    {
        if ($member->occupation) {
            return __('sanabel.occupation.'.$member->occupation);
        }

        return match (true) {
            (bool) $member->is_student => __('sanabel.masked.is_student'),
            (bool) $member->works => __('sanabel.masked.works'),
            default => null,
        };
    }

    /**
     * The paragraph, if the association has written one and signed it off,
     * and if their switch is open.
     */
    private function story(Beneficiary $case): ?string
    {
        return $this->switched('show_story') ? $case->approvedStory() : null;
    }

    /** Which of the asset kinds is a vehicle, for the association's «آلية». */
    private const VEHICLE_KINDS = ['car', 'tractor', 'motorcycle'];

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
