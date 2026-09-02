<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdjustmentCatalog extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'adjustments_catalog';

    protected $fillable = [
        'key', 'name_ar', 'amount', 'currency', 'region_id', 'effective_from', 'version', 'created_by',
    ];

    protected $casts = ['effective_from' => 'date', 'amount' => 'integer', 'version' => 'integer'];
}
