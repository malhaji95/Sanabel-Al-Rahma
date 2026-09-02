<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. The application exposes no update or delete route. */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_id', 'actor_role', 'action', 'entity_type', 'entity_id', 'before_json', 'after_json', 'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->created_at ??= now());
        static::updating(fn () => throw new \RuntimeException('audit_log is append-only'));
        static::deleting(fn () => throw new \RuntimeException('audit_log is append-only'));
    }
}
