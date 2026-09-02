<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralCardResource;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hard rule 4 — a provider sees only file number, validity and discount type. */
class ProviderReferralController extends Controller
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function verify(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can_('verify_referral'), 403, __('sanabel.permissions.denied'));

        $referral = Referral::with('beneficiary', 'provider')
            ->where('code', $code)
            ->firstOrFail();

        // A provider can only look at a card issued to itself.
        abort_unless(
            $request->user()->isAdmin() || $referral->provider->user_id === $request->user()->id,
            403,
            __('sanabel.permissions.denied'),
        );

        return response()->json(['data' => (new ReferralCardResource($referral))->resolve()]);
    }

    public function redeem(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can_('verify_referral'), 403, __('sanabel.permissions.denied'));

        $validated = $request->validate(['proof_media_id' => ['required', 'integer']]);

        $referral = Referral::with('beneficiary', 'provider')->where('code', $code)->firstOrFail();

        abort_unless(
            $request->user()->isAdmin() || $referral->provider->user_id === $request->user()->id,
            403,
            __('sanabel.permissions.denied'),
        );

        try {
            $referral = $this->referrals->redeem($referral, $validated['proof_media_id']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new ReferralCardResource($referral))->resolve()]);
    }
}
