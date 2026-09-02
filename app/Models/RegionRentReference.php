<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegionRentReference extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'region_rent_reference';

    protected $fillable = [
        'region_id', 'family_size_band', 'reference_rent', 'currency', 'effective_from', 'version', 'created_by',
    ];

    protected $casts = ['effective_from' => 'date', 'reference_rent' => 'integer', 'version' => 'integer'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
