<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\User;
use App\Models\Visit;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * docs/05-backlog.md T-14 and T-15.
 *
 * The device generates `client_uuid` and keeps the visit in IndexedDB until the
 * server confirms it. The unique index on `client_uuid` is what makes the sync
 * idempotent: syncing twice creates one visit, not two.
 *
 * A visit is never an overwrite. If the case changed on the server since the
 * device last saw it, the visit is still stored — flagged for admin review
 * instead of silently replacing anything.
 */
class VisitSyncService
{
    /**
     * @param  array  $payload  one queued visit from the device
     */
    public function sync(array $payload, User $delegate): Visit
    {
        $clientUuid = $payload['client_uuid'];

        // Idempotent by design — a retried or duplicated push returns the row
        // that already exists rather than creating a second one.
        $existing = Visit::where('client_uuid', $clientUuid)->first();

        if ($existing) {
            return $existing;
        }

        $beneficiary = Beneficiary::withoutGlobalScopes()->findOrFail($payload['beneficiary_id']);
        $baseVersionAt = isset($payload['base_version_at'])
            ? Carbon::parse($payload['base_version_at'])
            : null;

        $conflict = $this->hasServerChanged($beneficiary, $baseVersionAt);

        try {
            return DB::transaction(fn () => Visit::create([
                'beneficiary_id' => $beneficiary->getKey(),
                'delegate_id' => $delegate->getKey(),
                'client_uuid' => $clientUuid,
                'visited_at' => Carbon::parse($payload['visited_at']),
                'note_ar' => $payload['note_ar'] ?? null,
                'recommendation' => $payload['recommendation'] ?? null,
                'is_reassessment' => (bool) ($payload['is_reassessment'] ?? false),
                'payload_json' => $payload['data'] ?? null,
                'base_version_at' => $baseVersionAt,
                'conflict_flag' => $conflict,
                'conflict_reason' => $conflict
                    ? 'The case changed on the server after this device last synced. Stored as a new visit; nothing was overwritten.'
                    : null,
                'synced_at' => now(),
            ]));
        } catch (QueryException $e) {
            // Two devices pushing the same uuid at once: the unique index wins,
            // and the loser reads back the row the winner just wrote.
            if (DatabaseErrors::isUniqueViolation($e)) {
                return Visit::where('client_uuid', $clientUuid)->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * @param  array<int,array>  $queue
     * @return array{synced:int,conflicts:int,visit_ids:array<int,int>}
     */
    public function syncQueue(array $queue, User $delegate): array
    {
        $ids = [];
        $conflicts = 0;

        foreach ($queue as $payload) {
            $visit = $this->sync($payload, $delegate);
            $ids[$payload['client_uuid']] = $visit->getKey();
            $conflicts += $visit->conflict_flag ? 1 : 0;
        }

        return ['synced' => count($ids), 'conflicts' => $conflicts, 'visit_ids' => $ids];
    }

    private function hasServerChanged(Beneficiary $beneficiary, ?Carbon $baseVersionAt): bool
    {
        if (! $baseVersionAt) {
            return false;
        }

        return $beneficiary->updated_at !== null
            && $beneficiary->updated_at->greaterThan($baseVersionAt);
    }
}
