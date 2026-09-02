<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Rule 4 — every write to a case or money is logged.
 * Writes only. Reads are not logged in phase 1.
 */
trait Auditable
{
    /** Attributes never written into the audit log (rule 10). */
    protected array $auditRedacted = [
        'national_id_encrypted', 'national_id_hash', 'phone_encrypted', 'wallet_encrypted',
        'landlord_phone_encrypted', 'contact_encrypted', 'password', 'remember_token',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => $m->writeAudit('created', null, $m->auditPayload($m->getAttributes())));

        static::updated(function (Model $m) {
            $changed = array_keys($m->getDirty());
            $before = array_intersect_key($m->getOriginal(), array_flip($changed));

            $m->writeAudit('updated', $m->auditPayload($before), $m->auditPayload($m->getDirty()));
        });

        static::deleted(fn (Model $m) => $m->writeAudit('deleted', $m->auditPayload($m->getOriginal()), null));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $m) => $m->writeAudit('restored', null, $m->auditPayload($m->getAttributes())));
        }
    }

    protected function auditPayload(array $attributes): array
    {
        foreach ($this->auditRedacted as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    protected function writeAudit(string $action, ?array $before, ?array $after): void
    {
        $actor = Auth::user();

        AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_role' => $actor?->role?->key,
            'action' => $action,
            'entity_type' => static::class,
            'entity_id' => $this->getKey(),
            'before_json' => $before,
            'after_json' => $after,
        ]);
    }

    public function auditEntries()
    {
        return AuditLog::where('entity_type', static::class)->where('entity_id', $this->getKey());
    }
}
