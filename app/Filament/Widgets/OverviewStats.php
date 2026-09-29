<?php

namespace App\Filament\Widgets;

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Models\Setting;
use App\Models\SponsorshipInstallment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** T-35 — the weekly view without a manual export. */
class OverviewStats extends BaseWidget
{
    protected static ?int $sort = 1;

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
                ->color('success'),

            Stat::make(__('sanabel.dashboard.pending_approval'),
                Beneficiary::whereIn('status', ['pending_approval', 'verified'])->count())
                ->color('warning'),

            Stat::make(__('sanabel.dashboard.needs_reassessment'),
                Beneficiary::where('status', 'needs_reassessment')->count())
                ->color('danger'),

            Stat::make(__('sanabel.dashboard.pending_verification'), $pending)
                // The 48h target is shown as a number, nothing more.
                ->description(__('sanabel.dashboard.over_target', ['count' => $breaching, 'hours' => $targetHours]))
                ->color($breaching > 0 ? 'danger' : 'success'),

            // Split by kind: money already tied to named files, and money the
            // association still has to decide about. One total hid the second.
            Stat::make(__('sanabel.dashboard.earmarked_this_month'),
                number_format($thisMonth('earmarked')))
                ->description(config('sanabel.currency'))
                ->color('success'),

            Stat::make(__('sanabel.dashboard.general_this_month'),
                number_format($thisMonth('general')))
                ->description(__('sanabel.dashboard.general_help'))
                ->color('warning'),

            Stat::make(__('sanabel.dashboard.overdue_installments'),
                SponsorshipInstallment::where('status', 'overdue')->count())
                ->color('danger'),
        ];
    }
}
