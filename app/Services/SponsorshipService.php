<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\HouseholdMember;
use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Models\SponsorshipRate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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

    /*
     |---------------------------------------------------------------------
     | T-245 — sponsoring a number of a family's orphans at once.
     |---------------------------------------------------------------------
     |
     | «إذا كانت الأسرة تضم 4 أيتام، و3 منهم متاحين للكفالة … وعند دخول
     | المتبرع يظهر انه يوجد اكثر من يتيم ويستطيع تحديد كم يتيم يحتاج أن
     | يكفل», 3 November, and «كيفية منع ازدواج الكفالة، وكيف تنعكس ماليًا
     | على الأسرة دون تكرار», 4 November.
     |
     | The donor says how many, not which: the association assigns. What
     | they get is one sponsorship per child, each naming its own child
     | internally (T-238), each priced and dated from the association's own
     | rate for «كفالة يتيم» (T-210) rather than from the family's monthly
     | need, which is a different figure about a different thing.
     |
     | Rule 6 — the whole thing is one transaction and the candidate rows
     | are locked before they are read, because two donors pressing the
     | button in the same second would otherwise both see the same free
     | child. The partial unique index is the second lock, in the database,
     | for the case this code is bypassed.
     |
     | And the money: each sponsorship's installments are paid like any
     | other donation, and reach the family's coverage through the one
     | allocation table every other donation reaches it through. Three
     | sponsorships in one house are three contributions to one family's
     | coverage, counted once each — never a second time as the family's
     | own, because there is only one place coverage is read from.
     */
    public function sponsorOrphans(
        Beneficiary $family,
        Donor $donor,
        int $count,
        ?User $actor = null,
        ?CarbonInterface $startingFrom = null,
    ): Collection {
        if ($count < 1) {
            throw new \RuntimeException(__('sanabel.sponsorships.pick_at_least_one'));
        }

        $rate = SponsorshipRate::where('kind', 'orphan')->where('is_active', true)->first();

        if (! $rate) {
            throw new \RuntimeException(__('sanabel.sponsorships.no_rate_for_kind'));
        }

        return DB::transaction(function () use ($family, $donor, $count, $actor, $startingFrom, $rate) {
            // Locked before counted: between the count and the write is
            // exactly where a second donor would slip in.
            $available = $family->orphansAvailableForSponsorship()->lockForUpdate()->get();

            if ($available->count() < $count) {
                throw new \RuntimeException(__('sanabel.sponsorships.not_that_many_orphans', [
                    'available' => $available->count(),
                ]));
            }

            $from = ($startingFrom ? $startingFrom->copy() : now())->startOfMonth();

            return $available->take($count)->map(function (HouseholdMember $orphan) use (
                $family, $donor, $actor, $rate, $from
            ) {
                /*
                 | T-248 — «إذا توقفت الكفالة، الأقساط المدفوعة بتضل محفوظة
                 | وما بيرجع اليتيم متاح لكفالة ثانية عن نفس الفترة
                 | المغطاة», 4 November.
                 |
                 | A sponsorship that lapsed after three paid months leaves
                 | three months covered, and the next one starts at the
                 | fourth — not at this month, which would collect twice for
                 | a month somebody already paid for. The child is free
                 | again; the months that were paid for are not.
                 */
                $start = $this->firstUncoveredMonth($orphan, $from);
                $end = $start->copy()->addMonths(max(1, $rate->months) - 1)->endOfMonth();

                return $this->create([
                    'donor_id' => $donor->getKey(),
                    'beneficiary_id' => $family->getKey(),
                    'household_member_id' => $orphan->getKey(),
                    'amount' => $rate->amount,
                    'currency' => $rate->currency,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => 'active',
                    'created_by' => $actor?->getKey(),
                ]);
            })->values();
        });
    }

    /**
     * T-248 — the first month nobody has paid for this child yet.
     *
     * Read off the paid installments of every sponsorship that ever named
     * them, so it survives the sponsorship itself lapsing, completing or
     * being cancelled: what was paid for stays paid for. Never earlier than
     * the month asked for, so a child nobody has paid for starts now.
     */
    public function firstUncoveredMonth(HouseholdMember $member, CarbonInterface $notBefore): Carbon
    {
        $lastPaid = SponsorshipInstallment::query()
            ->where('status', 'paid')
            ->whereIn('sponsorship_id', Sponsorship::withTrashed()
                ->where('household_member_id', $member->getKey())
                ->select('id'))
            ->max('period');

        $after = $lastPaid
            ? Carbon::createFromFormat('Y-m', $lastPaid)->startOfMonth()->addMonth()
            : null;

        $floor = Carbon::parse($notBefore)->startOfMonth();

        return $after && $after->greaterThan($floor) ? $after : $floor;
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

            // Late is not lapsed. A donor hears the first long before the
            // second, while paying still keeps the sponsorship alive.
            $this->notifications->send(
                $installment->sponsorship?->donor?->user_id,
                'sponsorship_overdue',
                ['due_date' => $installment->due_date?->toDateString()],
            );
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

    /*
     | T-266 — an installment is marked paid against the transfer that paid it.
     |
     | This was written in T-25 and never called: the only place an
     | installment's status becomes «paid» is here, and nothing in the
     | application reached it. So an installment could be generated, fall
     | due, fall overdue, be counted overdue in the reports, and bring the
     | two-month lapse rule down on the sponsorship — and nobody could ever
     | say it had been paid. Left alone, every sponsorship lapsed.
     |
     | The guards are here rather than on the screen, because the screen is
     | not the only thing that will ever call this, and because each of them
     | is about money rather than about a form:
     |
     |   · verified only — rule 5's own line: unverified money is not money.
     |   · the sponsor's own transfer — a gift from somebody else does not
     |     discharge this sponsor's month.
     |   · and an installment already paid is left exactly as it is.
     |
     | What is deliberately NOT guarded here: how many months one transfer
     | may settle. A sponsor who pays a year at once is ordinary, and the
     | association has not said whether the figures must tally — so this
     | refuses nothing it was not told to refuse, and the question is theirs
     | to answer rather than ours to decide in a guard.
     */
    public function markPaid(SponsorshipInstallment $installment, int $donationId): SponsorshipInstallment
    {
        return DB::transaction(function () use ($installment, $donationId) {
            $locked = SponsorshipInstallment::whereKey($installment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'paid') {
                throw new \RuntimeException(__('sanabel.sponsorships.already_paid'));
            }

            $donation = Donation::whereKey($donationId)->lockForUpdate()->first();

            if (! $donation || $donation->status !== 'verified') {
                throw new \RuntimeException(__('sanabel.sponsorships.donation_not_verified'));
            }

            if ((int) $donation->donor_id !== (int) $locked->sponsorship->donor_id) {
                throw new \RuntimeException(__('sanabel.sponsorships.donation_not_the_sponsors'));
            }

            $locked->forceFill(['status' => 'paid', 'donation_id' => $donation->getKey()])->save();

            return $locked->refresh();
        });
    }
}
