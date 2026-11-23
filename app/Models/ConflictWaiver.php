<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-305 — a bar lifted for one named person on one named file, by somebody
 * else, for a reason written down.
 *
 * «عدم إتاحة تجاوز المنع إلا بصلاحية مستقلة, وسبب موثق, ومراجعة رقابية.»
 * The first is a key of its own, the second is required here, and the third
 * is the audit log, which the oversight committee reads.
 */
class ConflictWaiver extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_on' => 'date'];
    }

    /*
     | T-318 — «الاستثناء المؤقّت».
     |
     | An empty end date is a waiver with no end, which is every waiver
     | granted before today: giving those one now would change a decision
     | somebody made under different terms.
     |
     | The last day is inside the term, as it is for a provider's agreement
     | (T-314): a date cast holds midnight on that day, so comparing it to
     | `now()` would end the waiver on the morning of the day it runs until.
     */
    public function hasRunOut(): bool
    {
        return $this->expires_on !== null
            && $this->expires_on->lt(now()->startOfDay());
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
