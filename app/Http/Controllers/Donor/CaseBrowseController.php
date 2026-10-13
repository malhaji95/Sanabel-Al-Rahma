<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Beneficiary;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every donor-facing route answers with MaskedCaseResource and nothing else
 * (rule 2). Monthly and one-time cases are two separate lists.
 */
class CaseBrowseController extends Controller
{
    public function __construct(private readonly RankingService $ranking) {}

    public function index(Request $request, string $supportType = 'monthly'): JsonResponse
    {
        abort_unless(in_array($supportType, ['monthly', 'one_time'], true), 404);

        // The same list the browse screen draws, covered families included
        // (T-83), and each card answers the month its row carries.
        $list = $this->ranking->fundingList(
            $supportType,
            $request->integer('region_id') ?: null,
            withCovered: true,
        );

        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(1, $request->integer('per_page', 20)));

        return response()->json([
            'data' => $list->forPage($page, $perPage)
                ->map(fn (array $row) => (new MaskedCaseResource(
                    $row['beneficiary'],
                    $row['confirmed'],
                    $row['month'],
                ))->resolve())
                ->values()
                ->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $list->count()],
        ]);
    }

    public function show(Beneficiary $case): JsonResponse
    {
        abort_unless($case->status === 'published', 404);

        return response()->json(['data' => (new MaskedCaseResource($case))->resolve()]);
    }
}
