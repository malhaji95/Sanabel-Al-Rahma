<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Basket extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['donor_id', 'status', 'reserved_until', 'created_by'];

    protected $casts = ['reserved_until' => 'datetime'];

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BasketItem::class);
    }

    public function total(): int
    {
        return (int) $this->items()->sum('amount');
    }

    public function isLive(): bool
    {
        return $this->status === 'reserved' && $this->reserved_until?->isFuture();
    }
}
