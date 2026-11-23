<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AuditLog;
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
use App\Models\Setting;
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

    /**
     * The risk rules, as the association may switch them.
     *
     * T-321 — «قواعد مخاطر قابلة للضبط», phase two item 13. The two checks of
     * docs/07-decisions.md were written into the method below; they are now
     * read from the settings, both on, which is how they have always behaved.
     *
     * @return array<string,bool>
     */
    public static function riskRules(): array
    {
        return [
            'same_wallet' => (bool) Setting::value(
                'risk_rule_same_wallet',
                config('sanabel.setting_defaults.risk_rule_same_wallet')
            ),
            'same_phone_region' => (bool) Setting::value(
                'risk_rule_same_phone_region',
                config('sanabel.setting_defaults.risk_rule_same_phone_region')
            ),
        ];
    }

    /**
     * Holds a file for review, and says which rule held it.
     *
     * T-321 — «تُحال إلى المراجعة ولا ترفض وحدها». Both halves were already
     * true: the flag holds the file and nothing here rejects a family. What
     * was missing is the reason. The flag is a yes or a no, so whoever opens
     * a held file had to guess which check fired and go looking for the other
     * file themselves.
     *
     * The status is never touched. A rule is a reason to look, not a verdict.
     *
     * @return array<int,string> the rules that fired, empty if none did
     */
    public function flagIfSuspicious(Beneficiary $case): array
    {
        $rules = static::riskRules();

        $candidates = Beneficiary::withoutGlobalScopes()
            ->whereKeyNot($case->getKey())
            ->where(fn ($q) => $q->where('region_id', $case->region_id)->orWhereNotNull('wallet_encrypted'))
            ->get();

        $fired = [];

        foreach ($candidates as $other) {
            if ($rules['same_wallet']
                && $case->wallet_encrypted
                && $other->wallet_encrypted === $case->wallet_encrypted) {
                $fired['same_wallet'] = true;
            }

            if ($rules['same_phone_region']
                && $case->phone_encrypted
                && $other->phone_encrypted === $case->phone_encrypted
                && $other->region_id === $case->region_id) {
                $fired['same_phone_region'] = true;
            }
        }

        $fired = array_keys($fired);

        if ($fired !== []) {
            /*
             | The flag and the reasons, and nothing else. Not the status, not
             | a rejection, not the other file's number: the reviewer searches
             | by the rule's own criterion, which is what the rule names.
             */
            $case->forceFill([
                'duplicate_review_flag' => true,
                'risk_reasons_json' => $fired,
            ])->save();
        }

        return $fired;
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

    /*
     | T-317 — phase two, item 5: «تسجيل قراءة البيانات الحساسة».
     |
     | Rule 4 logs every write, and said plainly that reads are not logged in
     | phase 1. This is the read the association named first, and it is the
     | sharpest one in the system: somebody hands it a particular person's
     | identity number and it answers whether that person is on file. Nothing
     | recorded who had asked, or about whom.
     |
     | The screen and the partner API both come through here, so there is one
     | place to write it rather than two to keep in step.
     |
     | The number itself is never written down (rule 10). The row says who
     | asked, when, and which file answered — the file is named by its own id,
     | the way every other row in this log names its subject. A lookup that
     | found nothing is logged too, with no subject: a hundred misses in a
     | morning is the shape of somebody fishing, and the log that cannot show
     | that is the log that misses it.
     */
    private function logTheAsking(?Beneficiary $case, ?User $askedBy): void
    {
        AuditLog::create([
            'actor_id' => $askedBy?->getKey(),
            'actor_role' => $askedBy?->role?->key,
            'action' => 'read_by_national_id',
            'entity_type' => Beneficiary::class,
            'entity_id' => $case?->getKey(),
            'before_json' => null,
            'after_json' => ['found' => $case !== null],
        ]);
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

        $this->logTheAsking($case, $askedBy);

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
