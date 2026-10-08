<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visit extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'delegate_id', 'client_uuid', 'visited_at', 'latitude', 'longitude',
        'note_ar', 'recommendation',
        'is_reassessment', 'payload_json', 'conflict_flag', 'conflict_reason', 'base_version_at',
        'synced_at', 'created_by',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'synced_at' => 'datetime',
        'base_version_at' => 'datetime',
        'is_reassessment' => 'boolean',
        'conflict_flag' => 'boolean',
        'payload_json' => 'array',
    ];

    /**
     * Who may be shown where a family lives. A coordinate points at their
     * door, so it is not part of a masked case and never reaches a donor or
     * a partner association (decision of 6 October).
     */
    public const LOCATION_ROLES = [
        'delegate', 'area_supervisor', 'deputy_area_supervisor',
        'executive_director', 'deputy_executive_director', 'board_director', 'admin',
    ];

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public static function showsLocationTo(?User $user): bool
    {
        return $user !== null && in_array($user->role?->key, self::LOCATION_ROLES, true);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
