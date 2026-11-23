<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-322 — one upload of the accountant's statement, and what came of it.
 *
 * Audited, because approving one verifies money (rule 4).
 */
class DonationImport extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'approved_at' => 'datetime',
            'summary_json' => 'array',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DonationImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isStaged(): bool
    {
        return $this->status === 'staged';
    }
}
