<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasketItem extends Model
{
    use HasFactory;

    protected $fillable = ['basket_id', 'beneficiary_id', 'amount', 'currency'];

    protected $casts = ['amount' => 'integer'];

    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
