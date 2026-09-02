<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\Member;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * docs/03-rules.md §13. Entirely separate from donations: subscription money
 * goes to the membership fund and is never family coverage.
 */
class MembershipService
{
    public function generateSubscriptions(Member $member, Carbon $from, Carbon $to, int $amount, string $cycle = 'monthly'): int
    {
        $cursor = $from->copy()->startOfMonth();
        $created = 0;

        while ($cursor->lessThanOrEqualTo($to)) {
            Subscription::firstOrCreate(
                [
                    'member_id' => $member->getKey(),
                    'period' => $cycle === 'yearly' ? $cursor->format('Y') : $cursor->format('Y-m'),
                ],
                [
                    'amount' => $amount,
                    'currency' => config('sanabel.currency'),
                    'due_date' => $cursor->copy()->endOfMonth()->toDateString(),
                    'status' => 'due',
                    'fund_id' => Fund::byKey(Fund::MEMBERSHIP)->id,
                ],
            );

            $created++;
            $cycle === 'yearly' ? $cursor->addYear() : $cursor->addMonth();
        }

        return $created;
    }

    public function markPaid(Subscription $subscription, int $paymentMediaId): Subscription
    {
        $subscription->forceFill(['status' => 'paid', 'payment_media_id' => $paymentMediaId])->save();
        $this->refreshMemberStatus($subscription->member);

        return $subscription->refresh();
    }

    public function refreshMemberStatus(Member $member): Member
    {
        $overdue = $member->subscriptions()
            ->where('status', '!=', 'paid')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $member->forceFill([
            'status' => match (true) {
                $overdue >= 3 => 'suspended',
                $overdue >= 1 => 'overdue',
                default => 'active',
            },
        ])->save();

        return $member->refresh();
    }
}
