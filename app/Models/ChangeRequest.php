<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChangeRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /** Editing any of these after approval forces a recompute. */
    public const MATERIAL_FIELDS = [
        'region_id', 'support_type', 'monthly_rent', 'habitable_rooms', 'safety_band',
        'services_band', 'eviction_band', 'housing_type', 'person_class', 'dependent',
        'unable_to_earn', 'amount', 'is_stable', 'severity_band', 'economic_impact_band',
        'care_burden_band', 'monthly_medical_cost',
    ];

    /**
     * T-59 (PAY-05) — where the family's money is sent.
     *
     * A live file's payment details are not edited in place: the change is
     * raised, and somebody other than the person who raised it approves it.
     * One field today, so it is a list rather than a column: the association
     * decided on 29 September that a donor never pays a family directly, so
     * the wallet is the only payment detail the file carries.
     */
    public const PAYMENT_FIELDS = ['wallet_encrypted'];

    /** Statuses at which a file is live and its payment details are locked. */
    public const LIVE_STATUSES = ['approved', 'published', 'needs_reassessment', 'suspended'];

    /**
     * T-61 (AUTH-03) — the fields a change request may name at all.
     *
     * The payload used to be any array at all, and an approved one is filled
     * straight into the model. Beneficiary's fillable list carries the columns
     * that record who decided what and when, so a request whose stated reason
     * was "a phone number" could carry status, approved_by and published_at
     * and take a draft file straight to published — past the delegate, past
     * the area supervisor, past management. Two people are needed to do it
     * (T-59), but the second was reading a reason, not a JSON payload.
     *
     * An allowlist rather than a list of columns to block: a column nobody
     * thought of is then refused instead of permitted, and a legitimate field
     * that is missing fails loudly, naming itself, as a one-line data change.
     *
     * The decision columns are deliberately absent: status, approved_by,
     * approved_at, published_at, field_verified_*, endorsed_*, reject_reason_ar,
     * merged_into_id, duplicate_review_flag, national_id_hash, user_id and
     * created_by. Each of those is written by the service that owns the step.
     */
    public const CHANGEABLE_FIELDS = [
        // The family's own description.
        'first_name', 'father_name', 'family_name', 'phone_encrypted', 'marital_status',
        'association_id', 'zakat_category', 'case_classifications', 'is_shareable', 'previous_aid_ar',
        'legacy_file_no', 'urgency_deadline_at', 'documented_debt',
        // Its social survey.
        'gender', 'birth_date', 'town_ar', 'quarter_ar', 'latitude', 'longitude', 'works', 'workplace_ar',
        'declared_monthly_income',
        // The four consents (T-69). A consent is a fact about what the
        // family agreed to, so correcting one goes the same way as
        // correcting anything else on a live file.
        'donor_visit_consent', 'donor_visit_consent_on', 'privacy_policy_seen',
        'privacy_consent', 'privacy_consent_on', 'privacy_policy_version', 'declaration_on',
        'has_quran_memorizers', 'quran_memorizer_count', 'livelihood_proposal_ar',
        'proposal_skills_ar', 'proposal_cost', 'surveyed_by_ar', 'furnishing_ar',
        // Housing.
        'housing_type', 'type_other_ar', 'monthly_rent', 'rent_period', 'habitable_rooms',
        'bathrooms', 'has_kitchen', 'safety_band', 'services_band', 'eviction_band',
        'landlord_name_ar', 'landlord_phone_encrypted',
        // An income.
        'source_type', 'amount', 'is_stable', 'recurrence',
        // A member of the household.
        'name_ar', 'relation', 'relation_other_ar', 'birth_year', 'spouse_name_ar',
        'occupation', 'school_grade_ar', 'is_quran_memorizer', 'certificate_ar', 'skills_ar',
        'is_student', 'has_documented_condition', 'notes_ar', 'national_id_encrypted',
        'is_orphan', 'spouse_national_id_encrypted',
        'person_class', 'dependent', 'unable_to_earn',
        // A health record.
        'severity_band', 'economic_impact_band', 'care_burden_band', 'monthly_medical_cost',
        'description_ar', 'ongoing_treatment', 'treatment_place_ar', 'medicines_ar',
        'doctor_name_ar', 'doctor_phone_encrypted',
        // What the family owns, as the researcher answered it (T-64).
        'necessity', 'necessity_reason_ar', 'is_exploited', 'has_control', 'belongs_to',
        'kind_other_ar', 'estimated_value',
        // Where the money is sent. Locked on a live file, and this is the path.
        'wallet_encrypted',
        // The two the engine reads, and the region the rates come from.
        'region_id', 'support_type',
    ];

    protected $fillable = [
        'entity_type', 'entity_id', 'beneficiary_id', 'payload_json', 'old_json', 'reason_ar', 'is_material',
        'requested_by', 'status', 'reviewed_by', 'reviewed_at', 'review_note_ar', 'created_by',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'old_json' => 'array',
        'is_material' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public static function isMaterial(array $payload): bool
    {
        return (bool) array_intersect(array_keys($payload), self::MATERIAL_FIELDS);
    }

    public static function isPaymentChange(array $payload): bool
    {
        return (bool) array_intersect(array_keys($payload), self::PAYMENT_FIELDS);
    }

    public function touchesPaymentDetails(): bool
    {
        return self::isPaymentChange($this->payload_json ?? []);
    }

    /**
     * The fields in this payload that no change request may carry.
     *
     * @return array<int,string>
     */
    public static function fieldsNotChangeable(array $payload): array
    {
        return array_values(array_diff(array_keys($payload), self::CHANGEABLE_FIELDS));
    }
}
