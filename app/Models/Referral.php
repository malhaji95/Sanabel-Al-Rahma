<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Referral extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'provider_id', 'code', 'issued_at', 'expires_at',
        'status', 'used_at', 'proof_media_id', 'created_by',
    ];

    protected $casts = ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'issued' && $this->expires_at->isFuture();
    }
}
