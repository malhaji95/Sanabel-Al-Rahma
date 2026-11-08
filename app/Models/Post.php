<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Post extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $guarded = ['id'];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'consent_signed_on' => 'date',
    ];

    /** The path a piece walks. Each step names who took it and when. */
    public const STATUSES = ['draft', 'review', 'approved', 'published', 'archived'];

    /** The two states that mean an approver has signed the piece off. */
    public const SIGNED_OFF = ['approved', 'published'];

    /** What the reader actually sees. Changing any of these needs a new approval. */
    public const CONTENT_FIELDS = ['title_ar', 'excerpt_ar', 'body_ar', 'image'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

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

            $post->enforceApproval();

            // A story or a picture that belongs to a family does not go public
            // until that family has agreed to it, in writing, on the record.
            if ($post->status === 'published'
                && $post->beneficiary_id !== null
                && ! $post->hasConsent()) {
                throw new \RuntimeException(__('sanabel.post.consent_required'));
            }

            // The public pages read the flag; the status is the decision.
            $post->is_published = $post->status === 'published';

            if ($post->is_published && $post->published_at === null) {
                $post->published_at = now();
            }
        });
    }

    /**
     * Nothing reaches the reader without an approval signature on it, and an
     * amendment is a new piece of content: an editor who changes the title,
     * the summary, the body or the picture of a piece that was already signed
     * off sends it back to review, and it leaves the site until somebody with
     * `approve_content` signs it again (decision of 5 October).
     *
     * Seeders, tests and console commands write without an actor; the rule is
     * about what a person may do, so an unattended write is left alone.
     */
    private function enforceApproval(): void
    {
        $actor = Auth::user();

        if ($actor === null) {
            return;
        }

        $approver = $actor->can_('approve_content');
        $contentChanged = collect(self::CONTENT_FIELDS)->contains(fn (string $f) => $this->isDirty($f));

        if ($this->exists
            && in_array($this->getOriginal('status'), self::SIGNED_OFF, true)
            && $contentChanged
            && ! $approver) {
            $this->status = 'review';
            $this->approved_by = null;
            $this->approved_at = null;
        }

        // Moving a piece into approved or published *is* the approval.
        if (($this->isDirty('status') || ! $this->exists)
            && in_array($this->status, self::SIGNED_OFF, true)
            && ! $approver) {
            throw new \RuntimeException(__('sanabel.post.approval_required'));
        }

        $this->stampSignature($actor, $contentChanged);
    }

    /** Who took the last step on this piece, and when. */
    private function stampSignature(User $actor, bool $contentChanged): void
    {
        if (in_array($this->status, self::SIGNED_OFF, true)
            && ($this->isDirty('status') || $contentChanged)) {
            $this->approved_by = $actor->getKey();
            $this->approved_at = now();
        }

        if ($this->status === 'review' && $this->isDirty('status')) {
            $this->reviewed_by = $actor->getKey();
            $this->reviewed_at = now();
        }
    }

    /** True when this account may sign a piece off, and so may publish it. */
    public static function canBeApprovedBy(?User $user): bool
    {
        return (bool) $user?->can_('approve_content');
    }

    /** Signed by a named person, on a date, with the paper attached. */
    public function hasConsent(): bool
    {
        return filled($this->consent_signed_by_ar)
            && $this->consent_signed_on !== null
            && $this->consent_media_id !== null;
    }

    public static function sanitize(string $html): string
    {
        // Whole blocks first: strip_tags drops the <script> wrapper but keeps
        // the code between the tags, which would then be printed as text.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = preg_replace('#<(script|style)\b[^>]*>.*#is', '', $html);

        $html = strip_tags((string) $html, self::ALLOWED_TAGS);

        // Event handlers (onclick, onerror, …) survive strip_tags, because
        // they are attributes of tags we keep.
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        /*
         | T-271 — every href and src is read the way the browser will read
         | it, and kept only if it points somewhere safe.
         |
         | What stood here was a deny-list matching the literal text
         | «javascript:». It was got past four different ways, each of them
         | the same trick: the browser decodes the attribute before it reads
         | the scheme, and the regex did not. `href="javascript&#58;alert(1)"`
         | and `href="&#106;avascript:…"` and a tab or a newline inside the
         | word all came out of this function untouched and arrived in the
         | reader's browser as a live `javascript:` link. Verified in
         | Chromium: `a.protocol` read `javascript:` for all four.
         |
         | So the test is made on the decoded value, and it is an allow-list:
         | http, https, mailto, or a path of our own. Anything else loses the
         | attribute and keeps the tag.
         */
        $html = preg_replace_callback(
            '/\s(href|src)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
            function (array $m): string {
                $raw = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
                $raw = $raw !== '' ? $raw : ($m[5] ?? '');

                return self::urlIsSafe($raw) ? $m[0] : '';
            },
            (string) $html
        );

        return (string) $html;
    }

    /**
     * Whether a link may be kept, judged on what the browser will see.
     *
     * Entities are decoded and every control character and space removed
     * first, because a browser does both before it decides what the scheme
     * is — `java&#9;script:` is `javascript:` by the time it matters.
     */
    private static function urlIsSafe(string $value): bool
    {
        $probe = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\x00-\x20\x7F\xA0]+/u', '', $probe);
        $probe = mb_strtolower($probe);

        if ($probe === '') {
            return false;
        }

        // Protocol-relative: the scheme is whatever the page's is, so it is
        // not ours to vouch for.
        if (str_starts_with($probe, '//')) {
            return false;
        }

        // Our own pages and anchors within one.
        if (str_starts_with($probe, '/') || str_starts_with($probe, '#')) {
            return true;
        }

        if (preg_match('#^(https?|mailto):#', $probe) === 1) {
            return true;
        }

        // No scheme at all is a relative path, which is ours too.
        return preg_match('#^[a-z][a-z0-9+.\-]*:#', $probe) !== 1;
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
