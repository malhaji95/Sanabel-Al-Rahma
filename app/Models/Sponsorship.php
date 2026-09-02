<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sponsorship extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'donor_id', 'beneficiary_id', 'amount', 'currency', 'start_date', 'end_date', 'status', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'amount' => 'integer'];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SponsorshipInstallment::class);
    }

    /** Start and end date are both required (rule 8). */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if (blank($m->start_date) || blank($m->end_date)) {
                throw new \RuntimeException(__('sanabel.sponsorships.end_date_required'));
            }
        });
    }
}
