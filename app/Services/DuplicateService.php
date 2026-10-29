<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\BasketItem;
use App\Models\Beneficiary;
use App\Models\Delivery;
use App\Models\DistributionItem;
use App\Models\DonationAllocation;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Income;
use App\Models\Referral;
use App\Models\Region;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-rules.md §11. Exact national-ID match blocks a second file.
 * A similar phone or wallet only raises a review flag — never an auto-merge.
 */
class DuplicateService
{
    public function existingFile(string $nationalId): ?Beneficiary
    {
        return Beneficiary::withoutGlobalScopes()
            ->where('national_id_hash', Beneficiary::hashNationalId($nationalId))
            ->first();
    }

    public function guardAgainstDuplicate(string $nationalId): void
    {
        if ($this->existingFile($nationalId)) {
            throw new \RuntimeException(__('sanabel.cases.duplicate_national_id'));
        }
    }

    /** Same phone in the same region, or the same wallet (docs/07-decisions.md). */
    public function flagIfSuspicious(Beneficiary $case): bool
    {
        $candidates = Beneficiary::withoutGlobalScopes()
            ->whereKeyNot($case->getKey())
            ->where(fn ($q) => $q->where('region_id', $case->region_id)->orWhereNotNull('wallet_encrypted'))
            ->get();

        $suspicious = $candidates->contains(function (Beneficiary $other) use ($case) {
            $samePhoneAndRegion = $case->phone_encrypted
                && $other->phone_encrypted === $case->phone_encrypted
                && $other->region_id === $case->region_id;

            $sameWallet = $case->wallet_encrypted && $other->wallet_encrypted === $case->wallet_encrypted;

            return $samePhoneAndRegion || $sameWallet;
        });

        if ($suspicious) {
            $case->forceFill(['duplicate_review_flag' => true])->save();
        }

        return $suspicious;
    }

    /**
     * Admin-only. Picks a primary file and moves donations and history across.
     * Nothing is deleted — the duplicate is marked `merged` and points at the primary.
     */
    public function merge(Beneficiary $primary, Beneficiary $duplicate, User $admin): Beneficiary
    {
        if ($primary->is($duplicate)) {
            throw new \RuntimeException('A file cannot be merged into itself.');
        }

        return DB::transaction(function () use ($primary, $duplicate) {
            foreach ([
                DonationAllocation::class,
                BasketItem::class,
                Delivery::class,
                Sponsorship::class,
                Visit::class,
                Assessment::class,
                HouseholdMember::class,
                Income::class,
                HealthRecord::class,
                Referral::class,
                DistributionItem::class,
            ] as $model) {
                $model::where('beneficiary_id', $duplicate->getKey())
                    ->update(['beneficiary_id' => $primary->getKey()]);
            }

            $duplicate->forceFill([
                'status' => 'merged',
                'merged_into_id' => $primary->getKey(),
                'duplicate_review_flag' => false,
            ])->save();

            return $primary->refresh();
        });
    }

    /**
     * Hard rule 3 — an out-of-scope lookup returns four values only.
     *
     * @return array{registered:bool,has_active_assessment:bool,supported_this_period:bool,coverage:string}
     */
    public function coordinationLookup(string $nationalId, ?User $askedBy = null): array
    {
        $askedBy ??= auth()->user();
        $case = $this->existingFile($nationalId);

        if (! $case) {
            return [
                'registered' => false,
                'resident' => false,
                'assessment_state' => 'none',
                'has_active_assessment' => false,
                'supported_this_period' => false,
                'coverage' => 'none',
            ];
        }

        $assessment = $case->assessments()->where('status', 'approved')->latest('id')->first();
        $coverage = app(CoverageService::class);

        // A partner association asked to tell "we assessed them and it ran
        // out" from "we never assessed them at all" (decision of 6 October).
        // The two mean different things on their side: the first says come
        // back to us, the second says start from the beginning.
        $state = match (true) {
            $assessment === null => 'none',
            ! $assessment->valid_until || $assessment->valid_until->isFuture() => 'active',
            default => 'expired',
        };

        return [
            'registered' => true,
            // T-182 — «يظهر فقط مسجلة, مقيمة, وهل مدعومة, بدون اسم أو عنوان»,
            // 29 October. «مسجلة» has always been answered across the whole
            // country, because that is what stops two associations opening one
            // family twice. «مقيمة» is the other half of the question they
            // actually ask: is she one of mine. A place, answered as a yes or
            // a no — never the place itself.
            'resident' => $this->isResidentInScopeOf($case, $askedBy),
            'assessment_state' => $state,
            // Kept beside the new value: partner systems already read it.
            'has_active_assessment' => $state === 'active',
            'supported_this_period' => $coverage->confirmedForMonth($case) > 0,
            'coverage' => $coverage->coverageLabel($case),
        ];
    }

    /**
     * T-182 — whether this family lives inside the asking account's own area.
     *
     * «الاستعلام برقم الهوية يتم ضمن منطقته كاملة»: an association coordinates
     * over its whole area, not over the files it happens to have opened, so the
     * question is asked of the area above the locality and answered down every
     * village under it.
     *
     * An account tied to no region — the administrator, a director — is tied to
     * no area either, so there is no area for the family to be inside: the
     * answer is no, and the four values beside it are unchanged. It says
     * nothing about where the family is in any case; it says whether they are
     * here, which is what a coordinating association is deciding on.
     */
    private function isResidentInScopeOf(Beneficiary $case, ?User $askedBy): bool
    {
        $own = $askedBy?->region_id
            ? Region::withoutGlobalScopes()->find($askedBy->region_id)
            : null;

        $area = $own?->ancestorOfType('area') ?? $own;

        return $area !== null
            && in_array((int) $case->region_id, Region::descendantIds($area->id), true);
    }
}
