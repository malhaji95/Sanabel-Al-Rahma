<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'user_id', 'name_ar', 'type', 'specialty_ar', 'region_id', 'discount_type',
        'discount_value', 'valid_until', 'status', 'created_by',
    ];

    protected $casts = ['valid_until' => 'date', 'discount_value' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }
}
