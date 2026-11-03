<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * T-241 — one of the association's own card designs.
 *
 * «اختيار بطاقة الإهداء من التصاميم المعتمدة» — so the list is theirs to
 * fill, reorder and withdraw, and a design withdrawn today stays on the
 * cards already issued with it.
 */
class GiftCard extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)->orderBy('sort_order')->orderBy('id');
    }

    /** The artwork, or null while the association has not uploaded it yet. */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        // The public disk, like the banners: marketing artwork, deliberately
        // not the media disk that holds family documents behind signed URLs.
        return str_starts_with($this->image_path, 'http')
            ? $this->image_path
            : Storage::disk('public')->url($this->image_path);
    }
}
