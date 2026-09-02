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
    ];

    protected $casts = [
        'dependent' => 'boolean',
        'unable_to_earn' => 'boolean',
        'is_student' => 'boolean',
        'has_documented_condition' => 'boolean',
        'birth_year' => 'integer',
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
