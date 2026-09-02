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

    protected $fillable = [
        'entity_type', 'entity_id', 'payload_json', 'old_json', 'reason_ar', 'is_material',
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

    public static function isMaterial(array $payload): bool
    {
        return (bool) array_intersect(array_keys($payload), self::MATERIAL_FIELDS);
    }
}
