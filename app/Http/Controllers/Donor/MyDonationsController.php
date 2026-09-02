<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaskedCaseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyDonationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $donor = $request->user()->donor;

        $donations = $donor->donations()
            ->with('allocations.beneficiary')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'badge' => $donor->badge,
            'donations_count' => $donor->donations_count,
            'data' => $donations->getCollection()->map(fn ($donation) => [
                'transaction_ref' => $donation->transaction_ref,
                'amount' => $donation->amount,
                'currency' => $donation->currency,
                'status' => $donation->status,
                'status_label' => __('sanabel.donations.'.$donation->status),
                'created_at' => $donation->created_at->toDateString(),
                // Even the donor's own history shows only masked cases.
                'allocations' => $donation->allocations
                    ->filter(fn ($a) => $a->beneficiary !== null)
                    ->map(fn ($a) => [
                        'amount' => $a->amount,
                        'case' => (new MaskedCaseResource($a->beneficiary))->resolve(),
                    ])->values()->all(),
            ])->all(),
        ]);
    }
}
