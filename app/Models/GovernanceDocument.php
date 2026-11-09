<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-283 — a governed document: the thing that keeps its identity while its
 * text is replaced. «سياسة الخصوصية» is one document and five versions.
 */
class GovernanceDocument extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    public const KINDS = ['policy', 'procedure', 'decision', 'minutes', 'contract', 'license', 'other'];

    /** Condition 2, in the association's own order, least guarded first. */
    public const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'secret'];

    /** The two that are never handed out as a storage link (T-286 serves them). */
    public const RESTRICTED_CLASSIFICATIONS = ['confidential', 'secret'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'archived'];

    protected $fillable = [
        'title_ar', 'kind', 'classification', 'status', 'purpose_ar',
        'requires_acknowledgement', 'created_by',
    ];

    protected function casts(): array
    {
        return ['requires_acknowledgement' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GovernanceDocumentVersion::class, 'document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The version in force: the newest approved one, or nothing. */
    public function currentVersion(): ?GovernanceDocumentVersion
    {
        if ($this->relationLoaded('versions')) {
            return $this->versions
                ->where('status', 'approved')
                ->sortByDesc('version_no')
                ->first();
        }

        return $this->versions()
            ->where('status', 'approved')
            ->orderByDesc('version_no')
            ->first();
    }

    /** Named `isRestricted`, not `isGuarded`: Eloquent owns that second word. */
    public function isRestricted(): bool
    {
        return in_array($this->classification, self::RESTRICTED_CLASSIFICATIONS, true);
    }

    /** The number the next version will carry. */
    public function nextVersionNo(): int
    {
        return (int) $this->versions()->withTrashed()->max('version_no') + 1;
    }
}
