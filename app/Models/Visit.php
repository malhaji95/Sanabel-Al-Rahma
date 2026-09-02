<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TracksCreator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visit extends Model
{
    use Auditable, HasFactory, SoftDeletes, TracksCreator;

    protected $fillable = [
        'beneficiary_id', 'delegate_id', 'client_uuid', 'visited_at', 'note_ar', 'recommendation',
        'is_reassessment', 'payload_json', 'conflict_flag', 'conflict_reason', 'base_version_at',
        'synced_at', 'created_by',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'synced_at' => 'datetime',
        'base_version_at' => 'datetime',
        'is_reassessment' => 'boolean',
        'conflict_flag' => 'boolean',
        'payload_json' => 'array',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
