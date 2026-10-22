<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * T-113 — one piece of a page, and the shape it is drawn in.
 *
 * The kinds are the shapes the association's own design of 19 October uses,
 * and no more than that. Each is a row the content manager edits; adding a
 * step to «كيف نعمل», or giving «من نحن» the same three levels, is a row
 * rather than a release.
 */
class PageSection extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /**
     * heading   — opens a block: a chip, a title and a lead. «المستوى الأول».
     * group     — a smaller heading inside a block. «مسار العائلة».
     * card      — a numbered card in a row of them. The summary.
     * accordion — a title that opens onto a body, a control line and a link.
     * reference — a card that points somewhere, with a state chip on it.
     * document  — a reference text, open, that an anchor can be sent to.
     * statement — the quoted sentence on its own field.
     * note      — the aside: scope, caveat, what this page does not claim.
     * funding   — the staged-funding example, which is a component and not
     *             words: the association asked for it by name and it reads
     *             the association's own figures rather than invented ones.
     * person    — T-118. A member of a committee: a face, a name and what
     *             they do. The one kind that carries a picture.
     */
    public const KINDS = [
        'heading', 'group', 'card', 'accordion', 'reference', 'document',
        'statement', 'note', 'funding', 'person',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'is_published' => 'boolean',
        'show_image' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * The picture to draw, or null when there is none to draw.
     *
     * T-118 — null is also the answer when the association has turned the
     * switch off, and that is the whole of the feature they asked for: a
     * member who does not want their photograph published is drawn as the
     * mark instead, and is still named. The page asks this one question
     * rather than reading the column, so the switch cannot be honoured in
     * one place and forgotten in another.
     *
     * A path under `images/` ships with the application; anything else was
     * uploaded and lives on the public disk, the same as a home page card.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image || ! $this->show_image) {
            return null;
        }

        return str_starts_with($this->image, 'images/')
            ? asset($this->image)
            : Storage::disk('public')->url($this->image);
    }

    /**
     * The id this section answers to in the page's own anchors, or null.
     *
     * Sanitised rather than trusted: it goes into an `id` attribute and into
     * the href of every link that points at it, and a content manager typing
     * a space or a quotation mark into the box should not be able to break
     * the markup of a public page.
     */
    public function anchorId(): ?string
    {
        $anchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->anchor);

        return $anchor !== '' ? $anchor : null;
    }
}
