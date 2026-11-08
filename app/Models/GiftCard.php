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
        return $this->urlOf($this->image_path);
    }

    /*
     | T-264 — «كل عبارة بتكون عندنا بلونين وليس لون واحد», 8 November.
     |
     | The same phrase in a second colour. A design that has none goes on
     | behaving as it always did, and the giver is shown no switch at all.
     */
    public function hasAlternate(): bool
    {
        return filled($this->image_path_alt);
    }

    /** The picture for the variant the giver chose: 'b' where there is one. */
    public function imageUrlFor(?string $variant): ?string
    {
        return $variant === 'b' && $this->hasAlternate()
            ? $this->urlOf($this->image_path_alt)
            : $this->imageUrl();
    }

    /** What the giver reads under a variant, where the association wrote it. */
    public function variantLabel(string $variant): ?string
    {
        return $variant === 'b' ? $this->variant_alt_ar : $this->variant_ar;
    }

    private function urlOf(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        // The public disk, like the banners: marketing artwork, deliberately
        // not the media disk that holds family documents behind signed URLs.
        return str_starts_with($path, 'http')
            ? $path
            : Storage::disk('public')->url($path);
    }
}
