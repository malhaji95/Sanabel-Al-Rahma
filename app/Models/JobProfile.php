<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobProfile extends Model
{
    use Auditable, BelongsToRegion, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'trade_key', 'summary_ar', 'region_id', 'availability', 'status', 'created_by',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
