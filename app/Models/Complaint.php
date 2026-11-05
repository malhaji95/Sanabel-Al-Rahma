<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    /**
     * T-257 — the kinds their contact page offers, which are theirs and not
     * ours. The first four were here already.
     */
    public const CATEGORIES = [
        'service', 'delegate', 'payment', 'other',
        'inquiry', 'technical', 'suggestion', 'partnership', 'volunteering', 'confidential',
    ];

    /** The one kind that is also a wall: see `is_confidential`. */
    public const CONFIDENTIAL_CATEGORY = 'confidential';

    /**
     * T-257 — their six, mapped onto the four that existed plus the two
     * that did not. «مسندة» is their «قيد المراجعة»: a ticket somebody is
     * holding.
     */
    public const STATUSES = [
        'new', 'assigned', 'in_progress', 'awaiting_reporter', 'resolved', 'closed',
    ];

    /** Statuses at which a ticket is still somebody's to answer. */
    public const OPEN_STATUSES = ['new', 'assigned', 'in_progress', 'awaiting_reporter'];

    protected $fillable = [
        'reference_no', 'submitted_by', 'subject_ar', 'body_ar', 'category', 'against_user_id',
        'status', 'owner_id', 'resolution_ar', 'created_by',
        // T-257 — the visitor who has no account.
        'reporter_name_ar', 'reporter_phone_encrypted', 'reporter_phone_hash',
        'reporter_email', 'reporter_role', 'is_confidential', 'attachment_media_id',
    ];

    protected $casts = [
        'reporter_phone_encrypted' => 'encrypted',
        'is_confidential' => 'boolean',
    ];

    protected $hidden = ['reporter_phone_encrypted', 'reporter_phone_hash'];

    /** Rule 10 — a reporter's number never reaches the audit log either. */
    protected function auditRedactedKeys(): array
    {
        return array_merge($this->auditRedacted, [
            'reporter_phone_encrypted', 'reporter_phone_hash',
        ]);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'attachment_media_id');
    }

    /**
     * T-257 — the reference a visitor is given and quotes back.
     *
     * The year and a running count inside it, like every other reference
     * here. Soft-deleted rows are counted, so a number is never handed out
     * twice.
     */
    public static function nextReference(): string
    {
        $year = now()->format('y');
        $count = static::withTrashed()->whereYear('created_at', now()->year)->count() + 1;

        return sprintf('CMP-%s-%05d', $year, $count);
    }

    /**
     * The same hash a donor's number gets: it lets a ticket be found by the
     * contact that opened it without reading the column back.
     */
    public static function hashContact(string $contact): string
    {
        $contact = trim($contact);
        $digits = preg_replace('/\D+/', '', $contact);

        /*
         | An address is matched as written; a number by its last nine
         | digits, so «+963 912 345 678» finds the ticket that was opened
         | with «0912345678». A leading zero and a country key are the two
         | ways the same number is written in this country, and a visitor
         | following their own ticket should not have to remember which one
         | they typed a week ago. Nine digits plus an exact reference is a
         | narrow enough pair to be a lookup rather than a guess.
         */
        $normalised = mb_strlen($digits) >= 9
            ? mb_substr($digits, -9)
            : ($digits !== '' ? $digits : mb_strtolower($contact));

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    /**
     * T-257 — a confidential report is not on the general desk.
     *
     * «يجب أن يفرض الخادم فصلًا في الصلاحيات يمنع الدعم العام من قراءة
     * محتوى البلاغ أو مرفقاته» — their own words, and the reason this is a
     * query and not a hidden column on a screen.
     *
     * Keyed on the reading permission and not the handling one, because
     * they are two different acts: the oversight committee reads these
     * reports — that is why the path exists — and writes nothing, since
     * `is_read_only` refuses every write whatever the matrix says. Acting
     * on a report is the executive's.
     */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        if ($user && $user->can_('read_confidential_complaint')) {
            return $query;
        }

        return $query->where('is_confidential', false);
    }

    protected static function booted(): void
    {
        static::saving(function (self $m) {
            /** Rule 14 — a complaint is never assigned to the person it is about. */
            if ($m->owner_id && $m->against_user_id && $m->owner_id === $m->against_user_id) {
                throw new \RuntimeException(__('sanabel.complaints.owner_conflict'));
            }

            // T-257 — choosing the confidential kind is choosing the wall.
            if ($m->category === self::CONFIDENTIAL_CATEGORY) {
                $m->is_confidential = true;
            }
        });
    }
}
