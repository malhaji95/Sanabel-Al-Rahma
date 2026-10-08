<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Another association or body already supporting this family.
 *
 * Recorded, not deducted. Whether support from elsewhere lowers the need this
 * association answers for is a policy decision, and it has not been made; the
 * limited lookup between associations is what stops the same aid twice.
 */
class SupportSource extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
