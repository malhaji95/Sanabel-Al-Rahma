<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaskedCaseResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;

class CampaignController extends Controller
{
    public function index(): JsonResponse
    {
        $campaigns = Campaign::where('is_published', true)
            ->whereIn('status', ['active', 'funded', 'awaiting_execution'])
            ->with('beneficiary')
            ->paginate(20);

        return response()->json([
            'data' => $campaigns->getCollection()->map(fn (Campaign $c) => $this->present($c))->all(),
        ]);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->is_published, 404);

        return response()->json(['data' => $this->present($campaign)]);
    }

    private function present(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'title_ar' => $campaign->title_ar,
            'body_ar' => $campaign->body_ar,
            'goal_amount' => $campaign->goal_amount,
            'collected_amount' => $campaign->collected_amount,
            'reserved_amount' => $campaign->reserved_amount,
            'currency' => $campaign->currency,
            'progress_percent' => $campaign->progressPercent(),
            'accepts_pledges' => $campaign->acceptsPledges(),
            'status' => $campaign->status,
            // Shown to the donor before payment (docs/03-rules.md §7).
            'surplus_policy_text_ar' => $campaign->surplus_policy_text_ar,
            'case' => $campaign->beneficiary
                ? (new MaskedCaseResource($campaign->beneficiary))->resolve()
                : null,
        ];
    }
}
