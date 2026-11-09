<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-283 — one state of a document, and the first condition the association
 * set: «منع تعديل النسخة المعتمدة مباشرة».
 *
 * So an approved version refuses to be edited and refuses to be deleted. A
 * correction is a new version beside it; the old one becomes `superseded` and
 * stays readable, which is the only way the approval of the day means
 * anything later.
 */
class GovernanceDocumentVersion extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'superseded', 'rejected'];

    /** Once a version reaches one of these, its own row never changes again. */
    public const SEALED = ['approved', 'superseded'];

    /**
     * The columns the seal does not cover: a version is sealed against its
     * content being rewritten, not against the system recording that a newer
     * one has replaced it.
     */
    public const SEAL_EXEMPT = ['status', 'updated_at', 'deleted_at'];

    protected $fillable = [
        'document_id', 'version_no', 'media_id', 'summary_ar', 'status',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'decision_note_ar', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if (! in_array($version->getOriginal('status'), self::SEALED, true)) {
                return;
            }

            $touched = array_diff(array_keys($version->getDirty()), self::SEAL_EXEMPT);

            if ($touched !== []) {
                throw new \RuntimeException(__('sanabel.governance.version_sealed'));
            }

            // And a sealed version may only move on to being superseded.
            if ($version->isDirty('status') && $version->status !== 'superseded') {
                throw new \RuntimeException(__('sanabel.governance.version_sealed'));
            }
        });

        static::deleting(function (self $version) {
            if (in_array($version->status, self::SEALED, true)) {
                throw new \RuntimeException(__('sanabel.governance.version_sealed'));
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GovernanceDocument::class, 'document_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(Media::class, 'owner', 'owner_type', 'owner_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isSealed(): bool
    {
        return in_array($this->status, self::SEALED, true);
    }
}
