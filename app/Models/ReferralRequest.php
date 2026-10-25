<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-145 — a household asking for the health path.
 *
 * «نحتاج يكون عنده تقديم مسار الصحي اللي هو تقديم على اطباء او مشافي او
 * صيدلية», 28 October. The card at the end of this and the provider's quota
 * are older than it (T-32); this is the asking, which was happening by
 * telephone and being kept nowhere.
 */
class ReferralRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /** The provider types the association works with. */
    public const TYPES = ['doctor', 'hospital', 'pharmacy', 'lab', 'clinic'];

    public const STATUSES = ['open', 'issued', 'declined'];

    protected $fillable = [
        'beneficiary_id', 'provider_type', 'specialty_ar', 'note_ar', 'status',
        'referral_id', 'handled_by', 'handled_at', 'decline_reason_ar', 'created_by',
    ];

    protected $casts = ['handled_at' => 'datetime'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
