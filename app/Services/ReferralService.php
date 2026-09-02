<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Provider;
use App\Models\Referral;
use App\Models\Setting;
use Illuminate\Support\Str;

/** Single-use referral cards. A provider only ever sees three fields. */
class ReferralService
{
    public function issue(Beneficiary $beneficiary, Provider $provider): Referral
    {
        $days = (int) Setting::value(
            'referral_validity_days',
            config('sanabel.setting_defaults.referral_validity_days')
        );

        return Referral::create([
            'beneficiary_id' => $beneficiary->getKey(),
            'provider_id' => $provider->getKey(),
            'code' => strtoupper(Str::random(10)),
            'issued_at' => now(),
            'expires_at' => now()->addDays($days),
            'status' => 'issued',
        ]);
    }

    /** Refuses an expired, used or revoked card. */
    public function redeem(Referral $referral, int $proofMediaId): Referral
    {
        match ($referral->status) {
            'used' => throw new \RuntimeException(__('sanabel.referrals.already_used')),
            'revoked' => throw new \RuntimeException(__('sanabel.referrals.revoked')),
            default => null,
        };

        if ($referral->expires_at->isPast()) {
            $referral->forceFill(['status' => 'expired'])->save();

            throw new \RuntimeException(__('sanabel.referrals.expired'));
        }

        $referral->forceFill([
            'status' => 'used',
            'used_at' => now(),
            'proof_media_id' => $proofMediaId,
        ])->save();

        return $referral->refresh();
    }

    public function revoke(Referral $referral): Referral
    {
        $referral->forceFill(['status' => 'revoked'])->save();

        return $referral->refresh();
    }

    public function expireStale(): int
    {
        return Referral::where('status', 'issued')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}
