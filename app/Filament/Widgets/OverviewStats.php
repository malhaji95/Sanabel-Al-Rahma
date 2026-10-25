<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ApprovalFollowUp;
use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\DistributionResource;
use App\Filament\Resources\DonationResource;
use App\Filament\Resources\SponsorshipResource;
use App\Models\Basket;
use App\Models\Beneficiary;
use App\Models\DistributionItem;
use App\Models\Donation;
use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Services\CaseService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** T-35 — the weekly view without a manual export. */
class OverviewStats extends BaseWidget
{
    protected static ?int $sort = 1;

    /**
     * T-124 — «احتاج أن يكونوا قابلين للضغط ليتم التوجيه لكل صفحة مختصة»,
     * 26 October.
     *
     * A number on a dashboard is a question: which files are those? It used
     * to be a figure and nothing else, so the answer meant going to the list
     * and reproducing the filter by hand. Each card now carries the screen
     * it was counted from, with that filter already applied.
     */
    private function cases(string $status): string
    {
        return BeneficiaryResource::getUrl('index', [
            'tableFilters' => ['status' => ['value' => $status]],
        ]);
    }

    private function donations(?string $status = null): string
    {
        return DonationResource::getUrl('index', $status
            ? ['tableFilters' => ['status' => ['value' => $status]]]
            : []);
    }

    protected function getStats(): array
    {
        $targetHours = (int) Setting::value(
            'verification_target_hours',
            config('sanabel.setting_defaults.verification_target_hours')
        );

        $pending = Donation::where('status', 'pending')->count();

        $thisMonth = fn (string $designation) => (int) Donation::where('status', 'verified')
            ->whereNull('reversal_of_id')
            ->where('designation', $designation)
            ->where('verified_at', '>=', now()->startOfMonth())
            ->sum('amount');

        $breaching = Donation::where('status', 'pending')
            ->where('created_at', '<', now()->subHours($targetHours))
            ->count();

        return [
            Stat::make(__('sanabel.dashboard.published_cases'), Beneficiary::published()->count())
                ->description(__('sanabel.dashboard.published_help'))
                ->color('success')
                ->url($this->cases('published')),

            // The three sign-offs, each counted where it actually waits. One
            // card lumping them together said nothing about who was holding up
            // which file.
            Stat::make(__('sanabel.dashboard.awaiting_delegate'),
                Beneficiary::whereIn('status', CaseService::AWAITING_FIELD)->count())
                ->description(__('sanabel.dashboard.awaiting_delegate_help'))
                ->color('info')
                /*
                 | T-125 — the figure said how many and not whose. It opens
                 | the follow-up screen, where the same queue is broken down
                 | by the account that opened each file and by how long it
                 | has waited.
                 */
                ->url(ApprovalFollowUp::getUrl()),

            Stat::make(__('sanabel.dashboard.awaiting_supervisor'),
                Beneficiary::where('status', 'verified')->count())
                ->description(__('sanabel.dashboard.awaiting_supervisor_help'))
                ->color('info')
                ->url(ApprovalFollowUp::getUrl()),

            Stat::make(__('sanabel.dashboard.pending_approval'),
                Beneficiary::where('status', 'pending_approval')->count())
                ->color('warning')
                ->url($this->cases('pending_approval')),

            Stat::make(__('sanabel.dashboard.needs_reassessment'),
                Beneficiary::where('status', 'needs_reassessment')->count())
                ->color('danger')
                ->url($this->cases('needs_reassessment')),

            Stat::make(__('sanabel.dashboard.pending_verification'), $pending)
                // The 48h target is shown as a number, nothing more.
                ->description(__('sanabel.dashboard.over_target', ['count' => $breaching, 'hours' => $targetHours]))
                ->color($breaching > 0 ? 'danger' : 'success')
                ->url($this->donations('pending')),

            // Split by kind: money already tied to named files, and money the
            // association still has to decide about. One total hid the second.
            Stat::make(__('sanabel.dashboard.earmarked_this_month'),
                number_format($thisMonth('earmarked')))
                ->description(config('sanabel.currency'))
                ->color('success')
                ->url($this->donations('verified')),

            Stat::make(__('sanabel.dashboard.general_this_month'),
                number_format($thisMonth('general')))
                ->description(__('sanabel.dashboard.general_help'))
                ->color('warning')
                ->url($this->donations('verified')),

            Stat::make(__('sanabel.dashboard.overdue_installments'),
                SponsorshipInstallment::where('status', 'overdue')->count())
                ->color('danger')
                ->url(SponsorshipResource::getUrl('index')),

            // Money a donor has put a hold on but not yet transferred. It is
            // not income and it is not free: it is the third state, and the
            // association asked to see it rather than infer it.
            /*
             | The one card with nowhere to go: a hold is a basket, baskets
             | have no screen in the panel, and sending the reader to the
             | donations list would be sending them to money that has not
             | arrived. It is a figure, deliberately.
             */
            Stat::make(__('sanabel.dashboard.reserved_now'),
                number_format((int) Basket::where('status', 'reserved')
                    ->where('reserved_until', '>', now())
                    ->withSum('items', 'amount')
                    ->get()
                    ->sum('items_sum_amount')))
                ->description(__('sanabel.dashboard.reserved_help'))
                ->color('warning'),

            Stat::make(__('sanabel.dashboard.active_sponsorships'),
                Sponsorship::where('status', 'active')->count())
                ->color('success')
                ->url(SponsorshipResource::getUrl('index', [
                    'tableFilters' => ['status' => ['value' => 'active']],
                ])),

            // A distribution line that was attempted and did not land. Each
            // one carries its documented reason; this is only the count.
            Stat::make(__('sanabel.dashboard.failed_operations'),
                DistributionItem::where('status', 'failed')->count())
                ->description(__('sanabel.dashboard.failed_help'))
                ->color(DistributionItem::where('status', 'failed')->exists() ? 'danger' : 'success')
                ->url(DistributionResource::getUrl('index')),
        ];
    }
}
