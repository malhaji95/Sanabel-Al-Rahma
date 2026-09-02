<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Distribution extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'region_id', 'title_ar', 'total_amount', 'per_family_amount', 'currency', 'criteria_json',
        'list_json', 'status', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'criteria_json' => 'array',
        'list_json' => 'array',
        'approved_at' => 'datetime',
        'total_amount' => 'integer',
        'per_family_amount' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DistributionItem::class);
    }

    public function isFrozen(): bool
    {
        return $this->list_json !== null && $this->status !== 'draft';
    }

    /** Rule 12 — once approved, list_json is frozen and never regenerated. */
    protected static function booted(): void
    {
        static::updating(function (self $m) {
            $wasFrozen = $m->getOriginal('list_json') !== null
                && $m->getOriginal('status') !== 'draft';

            if ($wasFrozen && $m->isDirty('list_json')) {
                throw new \RuntimeException(__('sanabel.distributions.list_frozen'));
            }
        });
    }
}
