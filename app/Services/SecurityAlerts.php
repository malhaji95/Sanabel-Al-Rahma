<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;

/**
 * T-95 — OPS-03. The audit log told nobody.
 *
 * Every sensitive write has been recorded since the beginning, and that is
 * half of what the security file asks for: the other half is that somebody
 * hears about the handful of events nobody should be able to make quietly. A
 * log read once a quarter is a record, not a control.
 *
 * Two decisions, kept in two different places on purpose:
 *
 *   WHAT raises an alert is ours. These are the writes that change who may do
 *   what, what the system does to everybody, or what is kept of a family —
 *   and a list the association could edit down to nothing is not a control.
 *
 *   WHO hears it is theirs, and is a setting. Until they name somebody the
 *   list is empty and nothing is sent: an alert addressed to no one is worse
 *   than none, because it reads like a control that exists.
 *
 * Rule 10 — an alert carries the action, the kind of record and its id, and
 * nothing else. A name or a national ID in an inbox is the leak the log was
 * built to avoid.
 */
class SecurityAlerts
{
    /**
     * entity class => the attributes whose change is worth waking someone for.
     *
     * An empty list means every write to that entity raises one.
     */
    public const WATCHED = [
        // Who may do what, and whether an account works at all.
        \App\Models\User::class => ['role_id', 'is_active', 'region_id', 'password', 'two_factor_secret'],
        // One row changes a rule for everybody, without a deploy to review it.
        \App\Models\Setting::class => [],
        // What is kept of a family, and what is destroyed of it.
        \App\Models\Media::class => ['deleted_at'],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Raises an alert for an audit entry, if it is one of the watched writes
     * and the association has named anyone to receive it.
     */
    public function consider(AuditLog $entry): void
    {
        if (! $this->isWatched($entry)) {
            return;
        }

        foreach ($this->recipients() as $recipient) {
            $this->notifications->send($recipient, 'security_alert', [
                // Rule 10 — a reference and an id, never the record itself.
                'ref' => class_basename($entry->entity_type).'.'.$entry->action,
                'id' => $entry->entity_id,
            ]);
        }
    }

    private function isWatched(AuditLog $entry): bool
    {
        if (! array_key_exists($entry->entity_type, self::WATCHED)) {
            return false;
        }

        $attributes = self::WATCHED[$entry->entity_type];

        // Creation and deletion of a watched record always count; an update
        // counts when it touches one of the named attributes.
        if ($attributes === [] || $entry->action !== 'updated') {
            return true;
        }

        return array_intersect(array_keys($entry->after_json ?? []), $attributes) !== [];
    }

    /**
     * The accounts that hear it: whoever holds one of the roles the
     * association named on the settings screen.
     *
     * @return array<int, int>
     */
    private function recipients(): array
    {
        $roles = (array) Setting::value(
            'security_alert_roles',
            config('sanabel.setting_defaults.security_alert_roles')
        );

        if ($roles === []) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('key', $roles))
            ->pluck('id')
            ->all();
    }
}
