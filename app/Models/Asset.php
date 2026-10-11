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
 * Approved 11 October: owning something must not lower entitlement by itself.
 * An asset needed for the home, for work or for a living is recorded and
 * lowers nothing. A surplus, investment or non-essential asset that could be
 * sold has an effect, under criteria the association approves separately.
 *
 * The answer is the delegate's, recorded here. Nothing in the code decides it
 * from `kind`: the same tractor is a livelihood to one family and an
 * investment to another, which is exactly why they asked for the question.
 */
class Asset extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    /** أرض، منزل، محل، سيارة، جرار زراعي، دراجة نارية، أخرى */
    public const KINDS = ['land', 'house', 'shop', 'car', 'tractor', 'motorcycle', 'other'];

    /** ضروري للسكن أو العمل أو مصدر الرزق | زائد أو استثماري وقابل للتسييل */
    public const NECESSITIES = ['essential', 'surplus'];

    protected function casts(): array
    {
        return ['estimated_value' => 'integer'];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * Only an answered "surplus" counts. Null means the question has not been
     * put to anybody yet, and an unanswered asset is never read against the
     * family.
     */
    public function isSurplus(): bool
    {
        return $this->necessity === 'surplus';
    }

    public function isEssential(): bool
    {
        return $this->necessity === 'essential';
    }
}
