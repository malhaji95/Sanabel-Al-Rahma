<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the family owns: land, a house, a shop, a car, a tractor, a motorcycle.
 *
 * The survey asks for an estimated value beside each one. It is recorded and
 * nothing reads it: whether owning a tractor should lower what a family is
 * owed is the association's call, not a column's.
 */
class Asset extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    /** أرض، منزل، محل، سيارة، جرار زراعي، دراجة نارية، أخرى */
    public const KINDS = ['land', 'house', 'shop', 'car', 'tractor', 'motorcycle', 'other'];

    protected function casts(): array
    {
        return ['estimated_value' => 'integer'];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
