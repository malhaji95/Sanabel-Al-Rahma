<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScoringWeight extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['factor_key', 'weight', 'effective_from', 'version', 'created_by'];

    protected $casts = ['effective_from' => 'date', 'weight' => 'float', 'version' => 'integer'];
}
