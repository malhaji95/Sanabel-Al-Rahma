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
        'dependent', 'unable_to_earn', 'is_student', 'has_documented_condition', 'notes_ar', 'created_by',
        // From the association's social survey.
        'national_id_encrypted', 'relation_other_ar', 'birth_date', 'marital_status',
        'spouse_name_ar', 'occupation', 'school_grade_ar', 'works', 'workplace_ar',
        'is_quran_memorizer', 'certificate_ar', 'skills_ar',
    ];

    protected $hidden = ['national_id_encrypted'];

    /** طالب، موظف، باحث عن عمل، متقاعد، يتيم، غير ذلك */
    public const OCCUPATIONS = ['student', 'employed', 'job_seeker', 'retired', 'orphan', 'other'];

    protected $casts = [
        'dependent' => 'boolean',
        'unable_to_earn' => 'boolean',
        'is_student' => 'boolean',
        'has_documented_condition' => 'boolean',
        'birth_year' => 'integer',
        // A dependant's identity number is as private as the head's.
        'national_id_encrypted' => 'encrypted',
        'birth_date' => 'date',
        'works' => 'boolean',
        'is_quran_memorizer' => 'boolean',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function age(?int $now = null): int
    {
        return ($now ?? (int) date('Y')) - $this->birth_year;
    }
}
