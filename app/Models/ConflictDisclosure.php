<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-305 — what one person says about their own tie to one household.
 *
 * The answer is the obligation; the bar follows from it. A person who says
 * «لا» has said so on the record and may go on, and a person who says «نعم»
 * has done the right thing and is taken off the deciding of that file.
 */
class ConflictDisclosure extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    /** The ties the association listed, and a place for any other. */
    public const RELATIONS = [
        'close_kin', 'other_kin', 'neighbour', 'business', 'financial', 'other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'has_relationship' => 'boolean',
            'declared_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
