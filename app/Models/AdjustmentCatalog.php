<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdjustmentCatalog extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'adjustments_catalog';

    protected $fillable = [
        'key', 'name_ar', 'amount', 'is_active', 'currency', 'region_id', 'effective_from', 'version', 'created_by',
    ];

    protected $casts = ['effective_from' => 'date', 'amount' => 'integer', 'version' => 'integer', 'is_active' => 'boolean'];

    /*
     | T-286 — «ظهر خطأ (تعذر إتمام الطلب) أثناء تعديل أحد البنود المرجعية»,
     | 10 November.
     |
     | The screen has always offered a region on this row, through Filament's
     | `relationship('region', ...)`. The relation it names was never defined
     | here, so opening any adjustment for editing asked the model for a
     | relation it did not have and the page answered 500. Every adjustment,
     | every time: the association could read the list and never change a
     | line of it.
     |
     | Its two sisters, RegionRate and RegionRentReference, carry this and
     | always have. This row was the one that was missed.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
