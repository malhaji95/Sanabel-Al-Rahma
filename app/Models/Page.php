<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer'];

    /**
     * T-113 — the page's own shape: a flat, ordered list of typed sections.
     *
     * `body_ar` is still here and still shown above them, for the pages that
     * are a paragraph and nothing more. A page with sections is the same
     * page with more of them.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /** What a visitor is served: the published sections, in order. */
    public function publishedSections(): HasMany
    {
        return $this->sections()->where('is_published', true);
    }
}
