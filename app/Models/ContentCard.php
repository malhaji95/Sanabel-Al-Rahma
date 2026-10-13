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
 * T-89 — one card on the home page, or one link in the footer.
 *
 * `block` says which band of the page it belongs to. Nothing in the page is
 * written in a template: the association adds a way to give, reorders the
 * promises or renames a footer link from the panel.
 */
class ContentCard extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /** The bands of the page, in the order they appear on it. */
    public const BLOCKS = ['nav_link', 'feature', 'tile', 'advantage', 'footer_link'];

    protected $guarded = ['id'];

    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** One band, in its order. */
    public function scopeBlock(Builder $query, string $block): Builder
    {
        return $query->where('block', $block)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The picture, if the association has supplied one.
     *
     * A path under `images/` is a file shipped with the application and served
     * as it is; anything else was uploaded and lives on the public disk.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'images/')
            ? asset($this->image)
            : Storage::disk('public')->url($this->image);
    }
}
