<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'reference_no', 'submitted_by', 'subject_ar', 'body_ar', 'category', 'against_user_id',
        'status', 'owner_id', 'resolution_ar', 'created_by',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Rule 14 — a complaint is never assigned to the person it is about. */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            if ($m->owner_id && $m->against_user_id && $m->owner_id === $m->against_user_id) {
                throw new \RuntimeException(__('sanabel.complaints.owner_conflict'));
            }
        });
    }
}
