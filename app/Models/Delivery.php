<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected static function booted(): void
    {
        static::saving(function (self $delivery) {
            if ($delivery->donation_id === null
                && ! in_array($delivery->type, self::WITHOUT_A_TRANSFER, true)) {
                throw new \RuntimeException(__('sanabel.cases.delivery_needs_its_donation'));
            }
        });
    }

    /**
     * Kinds of help that move no transfer, so no transfer can be named. Anything
     * else is money, and a receipt for money has to say which transfer it
     * proves — otherwise a family that received three transfers and has two
     * receipts on file leaves nobody able to say which proves which.
     */
    public const WITHOUT_A_TRANSFER = ['in_kind', 'service'];

    protected $fillable = [
        'beneficiary_id', 'donation_id', 'type', 'proof_media_id', 'note_ar',
        'confirmed_by', 'confirmed_at', 'created_by',
    ];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    public function isProven(): bool
    {
        return $this->proof_media_id !== null && $this->confirmed_at !== null;
    }
}
