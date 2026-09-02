<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['beneficiary_id', 'source_type', 'amount', 'currency', 'is_stable', 'created_by'];

    protected $casts = ['is_stable' => 'boolean', 'amount' => 'integer'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
