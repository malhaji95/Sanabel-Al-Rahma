<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Services\DonorCaseList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every donor-facing route answers with MaskedCaseResource and nothing else
 * (rule 2). Monthly and one-time cases are two separate lists.
 */
class CaseBrowseController extends Controller
{
    public function __construct(private readonly DonorCaseList $cases) {}

    public function index(Request $request, string $supportType = 'monthly'): JsonResponse
    {
        abort_unless(in_array($supportType, ['monthly', 'one_time'], true), 404);

        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(1, $request->integer('per_page', 20)));

        // The same list the browse screen draws, covered families included
        // (T-83), each card answering the month its row carries, and behind
        // the same cache (T-87).
        $list = $this->cases->page(
            $supportType,
            $request->integer('region_id') ?: null,
            $page,
            $perPage,
            withCovered: true,
        );

        return response()->json([
            'data' => $list['cards'],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $list['total']],
        ]);
    }

    public function show(Beneficiary $case): JsonResponse
    {
        abort_unless($case->status === 'published', 404);

        return response()->json(['data' => (new MaskedCaseResource($case))->resolve()]);
    }
}
