<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HealthRecord extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'member_id', 'severity_band', 'economic_impact_band', 'care_burden_band',
        'monthly_medical_cost', 'currency', 'description_ar', 'evidence_media_id', 'created_by',
        'ongoing_treatment', 'treatment_place_ar', 'medicines_ar', 'doctor_name_ar',
        'doctor_phone_encrypted',
    ];

    // The doctor's own number is somebody's telephone number too.
    protected $hidden = ['description_ar', 'doctor_phone_encrypted'];

    protected $casts = [
        'severity_band' => 'integer',
        'economic_impact_band' => 'integer',
        'care_burden_band' => 'integer',
        'monthly_medical_cost' => 'integer',
        'ongoing_treatment' => 'boolean',
        'doctor_phone_encrypted' => 'encrypted',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(HouseholdMember::class, 'member_id');
    }
}
