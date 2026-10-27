<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HouseholdMember extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    public const PERSON_CLASSES = ['adult', 'child', 'elderly'];

    protected $fillable = [
        'beneficiary_id', 'relation', 'name_ar', 'birth_year', 'gender', 'person_class',
        'occupation_other_ar', 'dependent', 'unable_to_earn', 'is_student', 'has_documented_condition', 'notes_ar', 'created_by',
        // From the association's social survey.
        'national_id_encrypted', 'relation_other_ar', 'birth_date', 'marital_status',
        'spouse_name_ar', 'occupation', 'school_grade_ar', 'works', 'workplace_ar', 'work_income',
        'is_quran_memorizer', 'certificate_ar', 'skills_ar',
        // T-69 - «تصنيف اليتم», and a married dependant's husband.
        'is_orphan', 'spouse_national_id_encrypted',
        // T-100 — asked of every dependant on the survey, as of the head.
        'smokes',
        // T-175 — الكنية واسم الأب واسم الأم, تُملأ من الملف للابن والبنت.
        'family_name_ar', 'father_name_ar', 'mother_name_ar',
        // T-176 — المرض المزمن, يُسأل عن كل فرد لا عن العاجز وحده.
        'has_chronic_illness', 'chronic_illness_ar',
        // T-129 — ما تسأله كل صفة عن نفسها.
        'school_stage', 'sought_trade_ar', 'has_certificate', 'experience_years',
        'can_teach_quran', 'pension_income', 'condition_description_ar', 'doctor_phone_encrypted',
    ];

    protected $hidden = [
        'national_id_encrypted', 'spouse_national_id_encrypted',
        // T-129 — a doctor's number is a telephone number, like the head's.
        'doctor_phone_encrypted',
    ];

    /**
     * T-129 — the statuses the screen offers, and the keys the file stores.
     *
     * They used to be two different lists: this constant said `employed` and
     * `job_seeker` while every row written by the panel said `employee` and
     * `jobseeker`, because the screen reads its options from `lang/ar`. The
     * list the rows actually hold is the list, and «يتيم» is not on it — a
     * child is an orphan and also a student, and `is_orphan` has carried that
     * answer since T-69.
     */
    public const OCCUPATIONS = [
        'student', 'employee', 'worker', 'jobseeker', 'retired', 'housewife', 'unable', 'other',
    ];

    /** The statuses that mean a paid job: `works` is derived from these. */
    public const WORKING_OCCUPATIONS = ['employee', 'worker'];

    protected $casts = [
        'dependent' => 'boolean',
        'unable_to_earn' => 'boolean',
        'is_student' => 'boolean',
        'has_documented_condition' => 'boolean',
        'birth_year' => 'integer',
        // A dependant's identity number is as private as the head's, and so
        // is her husband's (T-69).
        'national_id_encrypted' => 'encrypted',
        'spouse_national_id_encrypted' => 'encrypted',
        'is_orphan' => 'boolean',
        'has_chronic_illness' => 'boolean',
        'smokes' => 'boolean',
        'birth_date' => 'date',
        'works' => 'boolean',
        'work_income' => 'integer',
        'is_quran_memorizer' => 'boolean',
        'has_certificate' => 'boolean',
        'can_teach_quran' => 'boolean',
        'experience_years' => 'integer',
        'pension_income' => 'integer',
        'doctor_phone_encrypted' => 'encrypted',
    ];

    protected static function booted(): void
    {
        /*
         | T-129 — the status is the answer; `works` and `is_student` follow it.
         |
         | Both were toggles of their own beside it, so a row could say
         | «موظف» and «لا يعمل» at the same time, and the masked card read the
         | toggle. They are derived here rather than on the one screen that
         | writes them, so a row arriving from the offline sync or an import
         | says the same thing as a row typed in the panel.
         */
        static::saving(function (self $member) {
            if ($member->isDirty('occupation') && $member->occupation !== null) {
                $member->works = in_array($member->occupation, self::WORKING_OCCUPATIONS, true);
                $member->is_student = $member->occupation === 'student';
            }
        });
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function age(?int $now = null): int
    {
        return ($now ?? (int) date('Y')) - $this->birth_year;
    }
}
