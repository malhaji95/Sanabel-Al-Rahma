<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-291 — a line in the book of who opened what.
 *
 * Written only, never edited and never deleted, like the audit log it sits
 * beside. It is its own table because rule 4 keeps the audit log for writes
 * and says reads are not logged in phase 1; the association asked for reads
 * of guarded documents to be logged, so they are logged here.
 */
class GovernanceAccessLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['opened', 'downloaded', 'refused'];

    protected $fillable = [
        'document_id', 'version_id', 'user_id', 'action', 'ability', 'classification',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(GovernanceDocument::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A line that nobody rewrites. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException(__('sanabel.governance.log_is_written_once')));
        static::deleting(fn () => throw new \RuntimeException(__('sanabel.governance.log_is_written_once')));
    }
}
