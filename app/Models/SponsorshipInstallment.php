<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sponsorship_id', 'period', 'due_date', 'amount', 'currency', 'status', 'donation_id', 'reminded_at',
    ];

    protected $casts = ['due_date' => 'date', 'reminded_at' => 'datetime', 'amount' => 'integer'];

    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
