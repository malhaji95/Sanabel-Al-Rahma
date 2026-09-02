<?php

namespace App\Models\Concerns;

use App\Models\Region;
use App\Models\Scopes\RegionScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToRegion
{
    public static function bootBelongsToRegion(): void
    {
        static::addGlobalScope(new RegionScope);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
