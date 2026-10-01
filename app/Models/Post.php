<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Post extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = ['is_published' => 'boolean', 'sort_order' => 'integer', 'published_at' => 'datetime'];

    /**
     * The body is rich text, so the page prints it as markup rather than
     * escaping it. Everything an editor can produce from the toolbar is on this
     * list; anything else — a <script>, an onclick, a javascript: link — is
     * dropped on save, so a content account cannot plant code that runs in an
     * administrator's browser.
     */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><ul><ol><li><h2><h3><blockquote><a><img><hr>';

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            if ($post->isDirty('body_ar')) {
                $post->body_ar = self::sanitize((string) $post->body_ar);
            }
        });
    }

    public static function sanitize(string $html): string
    {
        // Whole blocks first: strip_tags drops the <script> wrapper but keeps
        // the code between the tags, which would then be printed as text.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<(script|style)\b[^>]*>.*#is', '', $html);

        $html = strip_tags((string) $html, self::ALLOWED_TAGS);

        // Event handlers (onclick, onerror, …) and script-bearing URLs survive
        // strip_tags, because they are attributes of tags we keep.
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s(href|src)\s*=\s*("|\')?\s*(javascript|data|vbscript):[^"\'>]*("|\')?/i', '', $html);

        return (string) $html;
    }

    /** Marketing artwork on the public disk, like a banner's. */
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /** The editor's summary, or the opening of the body when they left it empty. */
    public function excerpt(int $length = 180): string
    {
        return filled($this->excerpt_ar)
            ? $this->excerpt_ar
            : Str::limit(trim(strip_tags((string) $this->body_ar)), $length);
    }
}
