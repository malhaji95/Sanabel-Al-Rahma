<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-292 — one person, one version, one date.
 *
 * Never a document: «قرأت السياسة» is worth nothing a year later because
 * nobody can say which policy was read.
 */
class GovernanceAcknowledgement extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = ['version_id', 'user_id', 'version_no', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime', 'version_no' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GovernanceDocumentVersion::class, 'version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
