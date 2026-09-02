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
    ];

    protected $hidden = ['landlord_name_ar', 'landlord_phone_encrypted'];

    protected function casts(): array
    {
        return [
            'landlord_phone_encrypted' => 'encrypted',
            'monthly_rent' => 'integer',
            'habitable_rooms' => 'integer',
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
