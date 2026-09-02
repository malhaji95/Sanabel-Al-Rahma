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
    ];

    protected $hidden = ['description_ar'];

    protected $casts = [
        'severity_band' => 'integer',
        'economic_impact_band' => 'integer',
        'care_burden_band' => 'integer',
        'monthly_medical_cost' => 'integer',
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
