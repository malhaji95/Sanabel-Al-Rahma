<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasketItem extends Model
{
    use HasFactory;

    protected $fillable = ['basket_id', 'beneficiary_id', 'campaign_id', 'amount', 'currency'];

    protected $casts = ['amount' => 'integer'];

    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /** Set when the donor pledged to a campaign rather than straight to a family. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
