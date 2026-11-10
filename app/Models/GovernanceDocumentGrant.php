<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-290 — one role, one ability, one document.
 *
 * The exception, not the rule: a classification decides what a role may do
 * with documents in general, and a grant lifts a single document above that
 * for a single role. It never lowers it — taking an ability away is done by
 * raising the classification, so there is one direction to read.
 */
class GovernanceDocumentGrant extends Model
{
    use Auditable, HasFactory, TracksCreator;

    public const ABILITIES = ['view', 'upload', 'edit', 'download', 'approve'];

    protected $fillable = ['document_id', 'role_id', 'ability', 'created_by'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(GovernanceDocument::class, 'document_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
