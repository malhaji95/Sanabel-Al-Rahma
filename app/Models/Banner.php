<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Banner extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Where the banner sends a visitor: the campaign it names, anchored on the
     * public list, or whatever URL was typed. A campaign wins, so a banner
     * pointing at one cannot drift to a stale address.
     */
    public function destination(): ?string
    {
        if ($this->campaign_id && $this->campaign?->is_published) {
            return route('campaigns.public').'#campaign-'.$this->campaign_id;
        }

        return $this->link ?: null;
    }

    /**
     * The public URL of the artwork, or null when none was uploaded.
     *
     * A path under `images/` is a file shipped with the application — the
     * design pack's own artwork — and is served as it is; anything else was
     * uploaded from the panel and lives on the public disk.
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
