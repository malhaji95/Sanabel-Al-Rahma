<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Override extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'assessment_id', 'auto_score', 'new_score', 'reason_ar',
        'requested_by', 'approved_by', 'expires_at', 'created_by',
    ];

    protected $casts = ['expires_at' => 'datetime', 'auto_score' => 'float', 'new_score' => 'float'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /** The automatic score is never erased. */
    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if ($m->isDirty('auto_score')) {
                throw new \RuntimeException('auto_score is immutable');
            }
        });
    }
}
