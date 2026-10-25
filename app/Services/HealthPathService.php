<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\ReferralRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T-145 — the queue in front of the health path.
 *
 * «ونحتاج يكون عنده تقديم مسار الصحي اللي هو تقديم على اطباء او مشافي او
 * صيدلية», 28 October.
 *
 * A household asks for a doctor, a hospital or a pharmacy; the association
 * answers by issuing the card it already issues, or by declining with a
 * reason. Nothing about the card, the provider's quota or the redemption
 * moves: this is what comes before them, and all it adds is that the asking
 * is written down.
 */
class HealthPathService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** A household's own asking, in their words. */
    public function apply(
        Beneficiary $case,
        string $providerType,
        ?string $specialtyAr = null,
        ?string $noteAr = null,
    ): ReferralRequest {
        if (! in_array($providerType, ReferralRequest::TYPES, true)) {
            throw new \RuntimeException(__('sanabel.health_path.unknown_type'));
        }

        /*
         | One open asking per kind at a time. A family that telephones three
         | times has one need, and three rows would be three cards.
         */
        $open = ReferralRequest::where('beneficiary_id', $case->getKey())
            ->where('provider_type', $providerType)
            ->where('status', 'open')
            ->first();

        if ($open) {
            throw new \RuntimeException(__('sanabel.health_path.already_open'));
        }

        $request = ReferralRequest::create([
            'beneficiary_id' => $case->getKey(),
            'provider_type' => $providerType,
            'specialty_ar' => $specialtyAr,
            'note_ar' => $noteAr,
            'status' => 'open',
        ]);

        // Whoever issues the cards hears that one has been asked for — the
        // association's key, not the provider's.
        // payload carries the file number and the kind, never the words the
        // family wrote (rule 10).
        foreach (User::holdersOf('issue_referral') as $userId) {
            $this->notifications->send($userId, 'health_path_requested', [
                'file_number' => $case->file_number,
            ]);
        }

        return $request;
    }

    /**
     * The association answers it with the card the provider will redeem.
     *
     * The card is issued by the service that has always issued them, so the
     * validity window, the code and the quota behave exactly as they do for a
     * card issued without an asking behind it.
     */
    public function issue(ReferralRequest $request, Provider $provider, User $handler): Referral
    {
        if (! $request->isOpen()) {
            throw new \RuntimeException(__('sanabel.health_path.not_open'));
        }

        if ($provider->type !== $request->provider_type) {
            throw new \RuntimeException(__('sanabel.health_path.type_mismatch'));
        }

        return DB::transaction(function () use ($request, $provider, $handler) {
            $referral = app(ReferralService::class)->issue($request->beneficiary, $provider);

            $request->forceFill([
                'status' => 'issued',
                'referral_id' => $referral->getKey(),
                'handled_by' => $handler->getKey(),
                'handled_at' => now(),
            ])->save();

            // The household is told that their card is waiting, and the code
            // is not in the message: they read it on their own page.
            $this->notifications->send(
                $request->beneficiary->user_id,
                'health_path_issued',
                ['file_number' => $request->beneficiary->file_number],
            );

            return $referral;
        });
    }

    /** Declined, with the reason on the row — never a silent close. */
    public function decline(ReferralRequest $request, User $handler, string $reasonAr): ReferralRequest
    {
        if (! $request->isOpen()) {
            throw new \RuntimeException(__('sanabel.health_path.not_open'));
        }

        if (blank($reasonAr)) {
            throw new \RuntimeException(__('sanabel.health_path.reason_required'));
        }

        $request->forceFill([
            'status' => 'declined',
            'decline_reason_ar' => $reasonAr,
            'handled_by' => $handler->getKey(),
            'handled_at' => now(),
        ])->save();

        $this->notifications->send(
            $request->beneficiary->user_id,
            'health_path_declined',
            ['file_number' => $request->beneficiary->file_number],
        );

        return $request->refresh();
    }
}
