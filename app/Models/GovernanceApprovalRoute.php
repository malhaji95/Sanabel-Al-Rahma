<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-290 — «تحديد جهة الاعتماد بحسب نوع الوثيقة … وليس حصر الاعتماد برئيس
 * مجلس الأمناء».
 *
 * A policy is approved by one body and a contract by another, and the
 * association says which. Per kind rather than per document: naming an
 * approver for every single minute of every meeting is a thing nobody keeps
 * up, and a rule nobody keeps is not a rule.
 */
class GovernanceApprovalRoute extends Model
{
    use Auditable, HasFactory, TracksCreator;

    protected $fillable = ['kind', 'role_id', 'created_by'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
