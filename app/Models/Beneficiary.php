<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    /** Rule 3 — nothing is hard-deleted. forceDelete is not used anywhere. */
    public const STATUSES = [
        'draft', 'pending_visit', 'verified', 'pending_approval', 'approved', 'published',
        'needs_reassessment', 'suspended', 'graduated', 'rejected', 'merged',
    ];

    /**
     * The eight categories zakat may be paid to, in the order of the verse.
     * A file is classified by choosing one of them; the reason is never typed
     * by hand, so the fund stays reportable and a free-text box does not turn
     * into a second account of the household.
     */
    /**
     * The survey's own «تصنيف الحالة». Added on 12 October for filtering, and
     * not a zakat category: فقير and مسكين are two of the eight channels, but
     * مرض and أرمل are not channels at all, so the two questions stay apart
     * and the zakat channel stays the management's own call.
     */
    public const CASE_CLASSIFICATIONS = [
        'poor', 'needy', 'orphan', 'widowed', 'illness', 'debtor', 'other',
    ];

    public const ZAKAT_CATEGORIES = [
        'poor', 'needy', 'workers', 'hearts', 'slaves', 'debtors', 'cause', 'wayfarer',
    ];

    protected $fillable = [
        'file_number', 'legacy_file_no', 'user_id', 'national_id_encrypted', 'national_id_hash', 'first_name', 'father_name',
        'family_name', 'phone_encrypted', 'region_id', 'association_id', 'marital_status', 'wallet_encrypted',
        'support_type', 'zakat_category', 'zakat_recommended', 'case_classifications', 'legacy_review_json', 'is_shareable', 'previous_aid_ar',
        // T-69 — what the filled dossier of 12 October showed.
        'quarter_ar', 'declared_monthly_income', 'donor_visit_consent', 'donor_visit_consent_on',
        'privacy_policy_seen', 'privacy_consent', 'privacy_consent_on', 'privacy_policy_version',
        'declaration_on',
        // The association's own social survey, carried into the file.
        'gender', 'birth_date', 'town_ar', 'latitude', 'longitude', 'works', 'workplace_ar',
        'has_quran_memorizers', 'quran_memorizer_count', 'livelihood_proposal_ar',
        'proposal_skills_ar', 'proposal_cost', 'surveyed_by_ar', 'furnishing_ar',
        // T-100 — the two the survey asks and the file had no room for.
        'smokes', 'situation_ar',
        // T-106 — the one paragraph written for the donor rather than for
        // the committee, and the two columns that say who signed it off.
        'story_ar', 'story_approved_by', 'story_approved_at',
        'urgency_deadline_at', 'documented_debt', 'status', 'last_assessment_at', 'next_assessment_due_at', 'source',
        'merged_into_id', 'duplicate_review_flag', 'approved_by', 'approved_at', 'reject_reason_ar',
        'field_verified_by', 'field_verified_at', 'endorsed_by', 'endorsed_at',
        'published_at', 'created_by',
    ];

    /**
     * The paragraph a donor may be shown, or nothing.
     *
     * T-106 — written, then approved, and withdrawn again by any edit. An
     * anonymised file has no story: the whole point of anonymising is that
     * the household stops being described.
     */
    public function approvedStory(): ?string
    {
        if ($this->isAnonymised() || $this->story_approved_at === null) {
            return null;
        }

        return filled($this->story_ar) ? $this->story_ar : null;
    }

    /** Who put their name to the family's story. */
    public function storyApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'story_approved_by');
    }

    /** The household's own account, which sees this file and no other. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class);
    }

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    public function supportSources()
    {
        return $this->hasMany(SupportSource::class);
    }

    public function caregivers()
    {
        return $this->hasMany(Caregiver::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function livestock()
    {
        return $this->hasMany(Livestock::class);
    }

    /** The family's own papers: identity, lease, medical report, debt paper. */
    public function documents()
    {
        return $this->morphMany(Media::class, 'owner', 'owner_type', 'owner_id');
    }

    protected $hidden = ['national_id_encrypted', 'national_id_hash', 'phone_encrypted', 'wallet_encrypted'];

    protected function casts(): array
    {
        return [
            'is_shareable' => 'boolean',
            // The old system's researcher review, kept as an archive (T-63).
            'legacy_review_json' => 'array',
            // T-69 — «تصنيف الحالة» is a list: the filled dossier answers it
            // «فقيرة، يتيمة».
            'case_classifications' => 'array',
            'declared_monthly_income' => 'integer',
            'donor_visit_consent' => 'boolean',
            'donor_visit_consent_on' => 'date',
            'zakat_recommended' => 'boolean',
            'privacy_policy_seen' => 'boolean',
            'privacy_consent' => 'boolean',
            'privacy_consent_on' => 'date',
            'declaration_on' => 'date',
            'birth_date' => 'date',
            'works' => 'boolean',
            'smokes' => 'boolean',
            'story_approved_at' => 'datetime',
            'has_quran_memorizers' => 'boolean',
            'national_id_encrypted' => 'encrypted',
            'phone_encrypted' => 'encrypted',
            'wallet_encrypted' => 'encrypted',
            'last_assessment_at' => 'datetime',
            'next_assessment_due_at' => 'datetime',
            'anonymise_requested_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'approved_at' => 'datetime',
            'field_verified_at' => 'datetime',
            'endorsed_at' => 'datetime',
            'urgency_deadline_at' => 'datetime',
            'documented_debt' => 'integer',
            'published_at' => 'datetime',
            'duplicate_review_flag' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function housing(): HasOne
    {
        return $this->hasOne(Housing::class);
    }

    /**
     * The association this family belongs to, if any. An association is a user
     * row with the `association` role, as `users.association_id` already
     * assumes. A family a delegate opened has none.
     */
    /** Who signed the file off in the field, and who endorsed it after them. */
    public function fieldVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'field_verified_by');
    }

    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(User::class, 'association_id');
    }

    public function healthRecords(): HasMany
    {
        return $this->hasMany(HealthRecord::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DonationAllocation::class);
    }

    public function basketItems(): HasMany
    {
        return $this->hasMany(BasketItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class);
    }

    public function jobProfile(): HasOne
    {
        return $this->hasOne(JobProfile::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function currentAssessment(): ?Assessment
    {
        // Honour an eager-loaded relation. Lists rank every published family and
        // ask each one for its assessment, so querying here regardless turned a
        // single `with('assessments')` into one query per family.
        if ($this->relationLoaded('assessments')) {
            return $this->assessments
                ->where('status', 'approved')
                ->sortByDesc('id')
                ->first();
        }

        return $this->assessments()->where('status', 'approved')->latest('id')->first();
    }

    public function scopePublished($query)
    {
        // Qualified: the donor list joins the assessments table, which carries
        // a `status` of its own, and an unqualified column there is ambiguous.
        return $query->where($this->getTable().'.status', 'published');
    }

    /**
     * T-69 — whether a donor may be sent to this family at all.
     *
     * The dossier asks the family directly, and the answer is theirs. No
     * answer is not a yes: a visit goes ahead only on a recorded agreement.
     */
    public function acceptsDonorVisit(): bool
    {
        return $this->donor_visit_consent === true;
    }

    /**
     * Whether the privacy consent on this file actually stands up.
     *
     * Their own sample answers «اطلاع على سياسة الخصوصية: لا» and «الموافقة
     * على سياسة الخصوصية: نعم» — a consent to a text nobody read. That is
     * recorded as it was given, because refusing the row would lose the
     * family; it is reported here instead, so it can be gone back over.
     */
    public function privacyConsentIsSound(): bool
    {
        return $this->privacy_consent === true && $this->privacy_policy_seen === true;
    }

    public function privacyConsentContradicts(): bool
    {
        return $this->privacy_consent === true && $this->privacy_policy_seen === false;
    }

    /** T-65 — the personal data is gone; the record and its history are not. */
    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    /*
     | T-59 (PAY-05) — where the family's money is sent is not edited in place
     | on a live file.
     |
     | The association's own path for an edit after approval is a change
     | request that somebody else approves, and the machinery was already
     | there; nothing forced the wallet through it, so a single account with
     | panel access could redirect a family's payments and the only trace
     | would be the audit row. This is the guard rather than a form rule,
     | because a form rule is one screen and this has to hold on every path:
     | the panel, the API and a console command alike.
     */
    private static bool $applyingApprovedChange = false;

    /** Opened only by CaseService while it applies a change a reviewer approved. */
    public static function applyingApprovedChange(callable $apply): mixed
    {
        self::$applyingApprovedChange = true;

        try {
            return $apply();
        } finally {
            self::$applyingApprovedChange = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (self $case) {
            if (self::$applyingApprovedChange) {
                return;
            }

            if (! $case->isDirty(ChangeRequest::PAYMENT_FIELDS)) {
                return;
            }

            // A file still being built is edited freely; it is a live file
            // whose payments are already flowing that needs the second pair
            // of eyes.
            if (! in_array($case->getOriginal('status'), ChangeRequest::LIVE_STATUSES, true)) {
                return;
            }

            throw new \RuntimeException(__('sanabel.cases.payment_change_needs_approval'));
        });

        /*
         | T-73 — the zakat channel is the management's decision, by their
         | answer of 13 October. The researcher records a recommendation in
         | `zakat_recommended`; the channel itself is signed by an account that
         | may approve a file, and by nobody else.
         |
         | On the model rather than on the screen, so the rule holds through
         | the panel, the API and the console alike. With no signed-in user
         | there is nobody to check — a seeder, an import or a console command —
         | and the rule does not apply.
         */
        $decision = function (self $case) {
            if (! $case->isDirty('zakat_category') || ! auth()->hasUser()) {
                return;
            }

            if (! auth()->user()->can_('approve_case')) {
                throw new \RuntimeException(__('sanabel.zakat.decision_is_managements'));
            }
        };

        static::creating($decision);
        static::updating($decision);

        /*
         | T-106 — the story is approved, not merely written.
         |
         | Any edit to the paragraph takes the approval off it, so that an
         | approval always names the words that were read. Without this, a
         | sentence could be signed off and then rewritten into anything at
         | all, and the signature would still be sitting under it.
         |
         | The approval columns themselves are exempt: setting them is the
         | act of approving.
         */
        static::updating(function (self $case) {
            if ($case->isDirty('story_ar') && ! $case->isDirty('story_approved_at')) {
                $case->story_approved_by = null;
                $case->story_approved_at = null;
            }
        });

        /*
         | T-74 — consent, by their decision of 13 October: «موافق ولا يوجد لا،
         | في حال غير موافق ما يدخل للنظام، ونعتمد انها ما تفتح الا بعد
         | الاطلاع عليها».
         |
         | So two refusals where there used to be a notice. Consent cannot be
         | recorded for a policy nobody was shown, and a file cannot leave
         | draft without both. A draft is still filled in any order — the
         | consent step is not always the first one reached.
         |
         | With no signed-in user the rule does not apply: the old-system
         | import carries rows exactly as the old forms were filled, including
         | the association's own dossier that says «الاطلاع: لا» beside
         | «الموافقة: نعم». Those rows keep the review notice; refusing them
         | would lose the family rather than fix the form.
         */
        $consent = function (self $case) {
            if (! auth()->hasUser()) {
                return;
            }

            if ($case->privacy_consent === true && $case->privacy_policy_seen !== true) {
                throw new \RuntimeException(__('sanabel.beneficiary.privacy_needs_seen_first'));
            }

            $leavingDraft = $case->isDirty('status')
                && $case->getOriginal('status') === 'draft'
                && $case->status !== 'draft';

            if ($leavingDraft && ! ($case->privacy_consent === true && $case->privacy_policy_seen === true)) {
                throw new \RuntimeException(__('sanabel.beneficiary.privacy_consent_required'));
            }
        };

        static::creating($consent);
        static::updating($consent);
    }

    /** sha256 of the normalised national id — rule 11, exact match blocks a second file. */
    public static function hashNationalId(string $nationalId): string
    {
        $normalised = preg_replace('/\D+/', '', $nationalId) ?: $nationalId;

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }
}
