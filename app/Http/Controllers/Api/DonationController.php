<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateTransactionRef;
use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DonationController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** The donor records a transfer they have already made outside the platform. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Donation::class);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'transaction_ref' => ['required', 'string', 'max:191'],
            'receipt_media_id' => ['nullable', 'integer'],
            'basket_id' => ['nullable', 'integer', 'exists:baskets,id'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
        ]);

        try {
            $donation = $this->gateway->record(array_merge($validated, [
                'donor_id' => $request->user()->donor->id,
                'route' => 'platform',
            ]));
        } catch (DuplicateTransactionRef $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'transaction_ref' => $donation->transaction_ref,
            'status' => $donation->status,
            'status_label' => __('sanabel.donations.pending'),
        ], 201);
    }

    public function verify(Request $request, Donation $donation): JsonResponse
    {
        $this->authorize('verify', $donation);

        $verified = $this->gateway->verify($donation, $request->user()->id);

        return response()->json(['status' => $verified->status]);
    }

    public function reject(Request $request, Donation $donation): JsonResponse
    {
        $this->authorize('reject', $donation);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $rejected = $this->gateway->reject($donation, $request->user()->id, $validated['reason']);

        return response()->json(['status' => $rejected->status, 'reason' => $rejected->reject_reason]);
    }
}
