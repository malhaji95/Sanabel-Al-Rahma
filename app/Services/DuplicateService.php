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
    public function coordinationLookup(string $nationalId): array
    {
        $case = $this->existingFile($nationalId);

        if (! $case) {
            return [
                'registered' => false,
                'has_active_assessment' => false,
                'supported_this_period' => false,
                'coverage' => 'none',
            ];
        }

        $assessment = $case->assessments()->where('status', 'approved')->latest('id')->first();
        $coverage = app(CoverageService::class);

        return [
            'registered' => true,
            'has_active_assessment' => $assessment !== null
                && (! $assessment->valid_until || $assessment->valid_until->isFuture()),
            'supported_this_period' => $coverage->confirmedSupport($case, now()->startOfMonth()) > 0,
            'coverage' => $coverage->coverageLabel($case),
        ];
    }
}
