<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One creditor the family owes, from the social survey.
 *
 * Recorded, never computed from: the need engine reads `documented_debt` on
 * the file, which a person sets deliberately, not the sum of these rows.
 * Whether a debt should move a score is the association's decision, and until
 * they make it these are the record of what was said.
 */
class Debt extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $hidden = ['creditor_phone_encrypted'];

    protected function casts(): array
    {
        return [
            // Somebody else's telephone number is still a telephone number.
            'creditor_phone_encrypted' => 'encrypted',
            'amount' => 'integer',
            'months' => 'integer',
            'has_due_date' => 'boolean',
            'due_date' => 'date',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
