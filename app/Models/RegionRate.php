<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegionRate extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'region_id', 'person_class', 'amount', 'currency', 'effective_from', 'version', 'created_by',
    ];

    protected $casts = ['effective_from' => 'date', 'amount' => 'integer', 'version' => 'integer'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
