<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Housing extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'housing';

    protected $fillable = [
        'beneficiary_id', 'housing_type', 'monthly_rent', 'currency', 'habitable_rooms',
        'safety_band', 'services_band', 'eviction_band', 'landlord_name_ar',
        'landlord_phone_encrypted', 'created_by',
        'type_other_ar', 'rent_period', 'bathrooms', 'has_kitchen',
        // T-169 — the impression of the house and what it lacks, beside the
        // researcher's sentence for the committee.
        'home_impression', 'missing_items', 'missing_items_other_ar',
    ];

    /** شهري أو سنوي، كما يسأل الاستبيان. */
    public const RENT_PERIODS = ['monthly', 'yearly'];

    protected $hidden = ['landlord_name_ar', 'landlord_phone_encrypted'];

    protected function casts(): array
    {
        return [
            // T-169 — more than one thing may be missing from one house.
            'missing_items' => 'array',
            'landlord_phone_encrypted' => 'encrypted',
            'monthly_rent' => 'integer',
            'habitable_rooms' => 'integer',
            'bathrooms' => 'integer',
            'has_kitchen' => 'boolean',
            'safety_band' => 'integer',
            'services_band' => 'integer',
            'eviction_band' => 'integer',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function isRenting(): bool
    {
        return $this->housing_type === 'rent';
    }
}
