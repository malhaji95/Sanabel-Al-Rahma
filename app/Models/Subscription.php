<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'member_id', 'period', 'amount', 'currency', 'due_date', 'status', 'payment_media_id', 'fund_id',
    ];

    protected $casts = ['due_date' => 'date', 'amount' => 'integer'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    /** Subscription money always lands in the membership fund — never family coverage. */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            $m->fund_id ??= Fund::byKey(Fund::MEMBERSHIP)->id;

            if (Fund::find($m->fund_id)?->key !== Fund::MEMBERSHIP) {
                throw new \RuntimeException('Subscriptions must belong to the membership fund.');
            }
        });
    }
}
