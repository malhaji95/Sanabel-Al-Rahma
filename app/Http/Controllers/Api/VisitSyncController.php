<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VisitSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The delegate PWA pushes its IndexedDB queue here. Idempotent by client_uuid,
 * so a repeated push after a flaky connection never creates a second visit.
 */
class VisitSyncController extends Controller
{
    public function __construct(private readonly VisitSyncService $sync) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can_('record_visit'), 403, __('sanabel.permissions.denied'));

        $validated = $request->validate([
            'visits' => ['required', 'array', 'max:100'],
            'visits.*.client_uuid' => ['required', 'uuid'],
            'visits.*.beneficiary_id' => ['required', 'integer', 'exists:beneficiaries,id'],
            'visits.*.visited_at' => ['required', 'date'],
            'visits.*.note_ar' => ['nullable', 'string'],
            'visits.*.recommendation' => ['nullable', 'string', 'max:64'],
            'visits.*.is_reassessment' => ['nullable', 'boolean'],
            'visits.*.base_version_at' => ['nullable', 'date'],
            // Optional on purpose: no signal, or a family who would rather
            // not, and the visit is still complete.
            'visits.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'visits.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'visits.*.data' => ['nullable', 'array'],
        ]);

        $result = $this->sync->syncQueue($validated['visits'], $request->user());

        return response()->json($result, 201);
    }
}
