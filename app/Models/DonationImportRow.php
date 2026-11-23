<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-322 — one line of the statement, and the verdict the matching reached.
 *
 * Not audited: these rows are a reading of a file, written once and never
 * edited. What the audit log cares about is the import they belong to and the
 * donations approving it verifies.
 */
class DonationImportRow extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** A row that will verify a donation when the import is approved. */
    public const READY = 'ready';

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount' => 'integer', 'row_no' => 'integer'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DonationImport::class, 'donation_import_id');
    }

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }
}
