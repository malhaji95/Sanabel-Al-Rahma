<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * docs/03-rules.md §8. One installment per month in the range.
 * An unpaid installment is never coverage — only a verified donation is.
 */
class SponsorshipService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function create(array $attributes): Sponsorship
    {
        return DB::transaction(function () use ($attributes) {
            $sponsorship = Sponsorship::create($attributes);
            // refresh() so column defaults filled in by the database (currency)
            // are on the model before the installments copy them.
            $this->generateInstallments($sponsorship->refresh());

            return $sponsorship->refresh();
        });
    }

    /** One row per month between start_date and end_date, inclusive. */
    public function generateInstallments(Sponsorship $sponsorship): int
    {
        $cursor = $sponsorship->start_date->copy()->startOfMonth();
        $end = $sponsorship->end_date->copy()->startOfMonth();
        $created = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            SponsorshipInstallment::firstOrCreate(
                ['sponsorship_id' => $sponsorship->getKey(), 'period' => $cursor->format('Y-m')],
                [
                    'due_date' => $cursor->copy()->endOfMonth()->toDateString(),
                    'amount' => $sponsorship->amount,
                    'currency' => $sponsorship->currency ?? config('sanabel.currency'),
                    'status' => 'due',
                ],
            );

            $created++;
            $cursor->addMonth();
        }

        return $created;
    }

    /** Reminder to the donor 3 days before the due date. */
    public function sendReminders(int $daysBefore = 3): int
    {
        $installments = SponsorshipInstallment::query()
            ->where('status', 'due')
            ->whereNull('reminded_at')
            ->whereDate('due_date', '<=', now()->addDays($daysBefore)->toDateString())
            ->with('sponsorship.donor')
            ->get();

        foreach ($installments as $installment) {
            $this->notifications->send(
                $installment->sponsorship->donor->user_id,
                'sponsorship_due',
                ['period' => $installment->period, 'due_date' => $installment->due_date->toDateString()],
            );

            $installment->forceFill(['reminded_at' => now()])->save();
        }

        return $installments->count();
    }

    /**
     * Past the grace period an installment goes overdue; two consecutive unpaid
     * ones lapse the sponsorship and the family returns to the funding list.
     */
    public function markOverdueAndLapse(): array
    {
        $graceDays = (int) Setting::value(
            'sponsorship_grace_days',
            config('sanabel.setting_defaults.sponsorship_grace_days')
        );
        $lapseAfter = (int) Setting::value(
            'sponsorship_lapse_after_unpaid',
            config('sanabel.setting_defaults.sponsorship_lapse_after_unpaid')
        );

        $overdue = SponsorshipInstallment::query()
            ->where('status', 'due')
            ->whereDate('due_date', '<', now()->subDays($graceDays)->toDateString())
            ->get();

        foreach ($overdue as $installment) {
            $installment->forceFill(['status' => 'overdue'])->save();
        }

        $lapsed = 0;

        foreach (Sponsorship::where('status', 'active')->with('installments')->get() as $sponsorship) {
            if ($this->consecutiveUnpaid($sponsorship) >= $lapseAfter) {
                $sponsorship->forceFill(['status' => 'lapsed'])->save();

                $this->notifications->send(
                    $sponsorship->donor->user_id,
                    'sponsorship_lapsed',
                    ['id' => $sponsorship->getKey()],
                );

                $lapsed++;
            }
        }

        return ['overdue' => $overdue->count(), 'lapsed' => $lapsed];
    }

    /** The longest run of unpaid installments ending at the most recent due one. */
    public function consecutiveUnpaid(Sponsorship $sponsorship): int
    {
        $past = $sponsorship->installments()
            ->whereDate('due_date', '<=', now()->toDateString())
            ->orderByDesc('due_date')
            ->get();

        $run = 0;

        foreach ($past as $installment) {
            if ($installment->status === 'paid') {
                break;
            }

            $run++;
        }

        return $run;
    }

    public function markPaid(SponsorshipInstallment $installment, int $donationId): SponsorshipInstallment
    {
        $installment->forceFill(['status' => 'paid', 'donation_id' => $donationId])->save();

        return $installment->refresh();
    }
}
