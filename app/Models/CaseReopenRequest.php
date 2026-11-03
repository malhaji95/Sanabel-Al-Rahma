<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * T-239 — a request to reopen a file that had ended, and the record of who
 * asked and who agreed.
 *
 * The association asked for «سجلًا يوضح من طلب إعادة الفتح ومن وافق عليه».
 * The audit log would have held it, but a log is read by whoever knows to
 * look: this is a row on a screen, filtered to what is still waiting.
 */
class CaseReopenRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $table = 'case_reopen_requests';

    protected $fillable = [
        'beneficiary_id', 'from_status', 'reason_ar', 'note_ar', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note_ar', 'to_status', 'created_by',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
