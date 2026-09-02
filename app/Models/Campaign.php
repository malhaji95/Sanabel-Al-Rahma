<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'title_ar', 'body_ar', 'goal_amount', 'collected_amount', 'reserved_amount',
        'currency', 'wallet_encrypted', 'surplus_policy_text_ar', 'is_published', 'status',
        'fund_id', 'created_by',
    ];

    protected $hidden = ['wallet_encrypted'];

    protected function casts(): array
    {
        return [
            'wallet_encrypted' => 'encrypted',
            'is_published' => 'boolean',
            'goal_amount' => 'integer',
            'collected_amount' => 'integer',
            'reserved_amount' => 'integer',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function progressPercent(): int
    {
        return $this->goal_amount > 0
            ? (int) min(100, floor(100 * $this->collected_amount / $this->goal_amount))
            : 0;
    }

    public function acceptsPledges(): bool
    {
        return $this->status === 'active'
            && ($this->collected_amount + $this->reserved_amount) < $this->goal_amount;
    }

    /** surplus_policy_text_ar is mandatory before publishing (rule 7). */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->is_published && blank($m->surplus_policy_text_ar)) {
                throw new \RuntimeException(__('sanabel.campaigns.surplus_policy_required'));
            }
        });
    }
}
