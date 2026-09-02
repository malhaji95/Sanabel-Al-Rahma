<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoordinationLookupResource;
use App\Services\DuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hard rule 3 — an out-of-scope lookup by national ID returns four values only.
 * Rate-limited, because it is the one route that takes a national ID as input.
 */
class CoordinationController extends Controller
{
    public function __construct(private readonly DuplicateService $duplicates) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'national_id' => ['required', 'string', 'max:64'],
        ]);

        abort_unless($request->user()->can_('search_by_national_id'), 403, __('sanabel.permissions.denied'));

        $result = $this->duplicates->coordinationLookup($validated['national_id']);

        return response()->json([
            'data' => (new CoordinationLookupResource($result))->resolve(),
        ]);
    }
}
