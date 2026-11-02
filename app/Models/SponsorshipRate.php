<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * T-210 — what one kind of sponsorship costs a month, and for how long.
 *
 * «تحديد مبلغ كل نوع من الكفالات يكون من صلاحيات الإدارة وفق ضوابط معتمدة»,
 * 1 November. So it is a row with a hand and a date behind it (rule 4)
 * rather than a number in the code, and the donor screens read it instead
 * of reading the family's own monthly need — which is a different figure
 * about a different thing.
 */
class SponsorshipRate extends Model
{
    use Auditable, HasFactory, TracksCreator;

    protected $fillable = ['kind', 'amount', 'currency', 'months', 'is_active', 'notes_ar', 'created_by'];

    protected $casts = [
        'amount' => 'integer',
        'months' => 'integer',
        'is_active' => 'boolean',
    ];

    /** One currency until the association uses two (rule 9). */
    protected static function booted(): void
    {
        static::creating(function (self $rate) {
            $rate->currency = $rate->currency ?: config('sanabel.currency');
        });
    }

    /** The price list a donor screen reads, keyed by kind. */
    public static function published(): array
    {
        return static::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (self $rate) => [$rate->kind => [
                'amount' => $rate->amount,
                'currency' => $rate->currency,
                'months' => $rate->months,
            ]])
            ->all();
    }
}
