<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Region extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = ['parent_id', 'name_ar', 'type', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(RegionRate::class);
    }

    public function rentReferences(): HasMany
    {
        return $this->hasMany(RegionRentReference::class);
    }

    /** The region and every region beneath it. */
    public static function descendantIds(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];

        while ($frontier) {
            $frontier = static::withoutGlobalScopes()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /** Walks up to the nearest ancestor of the given type (used for donor-safe area labels). */
    public function ancestorOfType(string $type): ?self
    {
        $node = $this;

        while ($node) {
            if ($node->type === $type) {
                return $node;
            }
            $node = $node->parent;
        }

        return null;
    }
}
