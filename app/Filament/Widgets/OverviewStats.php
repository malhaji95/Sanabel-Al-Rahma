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
use App\Models\User;
use App\Services\CaseService;
use App\Services\PermissionService;
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
    private function cases(string $status, bool $mine = false): string
    {
        return BeneficiaryResource::getUrl('index', [
            'tableFilters' => array_filter([
                'status' => ['value' => $status],
                // T-126 — a card that counts one account's files opens the
                // list narrowed to that account's files, not to the village.
                'mine' => $mine ? ['isActive' => true] : null,
            ]),
        ]);
    }

    private function donations(?string $status = null): string
    {
        return DonationResource::getUrl('index', $status
            ? ['tableFilters' => ['status' => ['value' => $status]]]
            : []);
    }

    /**
     * T-126 — «دخلت على حساب مندوب ميداني وبلوحة التحكم ضمن بطاقات KPI Cards
     * تظهر له معلومات تخص المدير التنفيذي», 27 October.
     *
     * One list of cards for everybody meant a delegate opened the panel onto
     * the association's cash position, and the finance desk onto a review
     * queue it has no part in. Neither can act on the other's figures, and a
     * dashboard nobody can act on is read once and then ignored.
     *
     * So the cards follow the reports scope the matrix already grants, which
     * is the answer every report screen in the panel already gives:
     *
     *   · own  — the files this account opened itself, by the step each one
     *            is waiting at: a delegate, a partner association, a data
     *            entry account;
     *   · area — the association's review queues, narrowed to the account's
     *            own region by the region scope, as every list is;
     *   · all  — those queues, and the money.
     *
     * An account with no claim on a family file is shown none of them: the
     * treasurer reads money, not families.
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $scope = app(PermissionService::class)->scopeFor($user, 'view_reports');

        $files = ! $user?->can_('view_full_case') ? []
            : match ($scope) {
                'own' => $this->ownFiles($user),
                // T-134 — the same queues, and the screen says whose they are:
                // every count below reads `beneficiaries` through the region
                // scope, so an area supervisor's figures are their own area's.
                // Saying so is the difference between a number they trust and
                // one they assume belongs to somebody else.
                'area' => $this->reviewQueues(mine: true),
                'all' => $this->reviewQueues(),
                default => [],
            };

        return $scope === 'all' ? [...$files, ...$this->money()] : $files;
    }

    /**
     * The account's own files, by the step each one is waiting at.
     *
     * «اريد ان يظهر له الملفات المنشورة بانتظار الاعتماد وهي الامور التي
     * تخصه هو بمنطقته». Counted on `created_by`, so the figure is this
     * account's own work and not the village's, and every card opens the
     * list narrowed the same way.
     *
     * @return array<int,Stat>
     */
    private function ownFiles(User $user): array
    {
        $mine = fn (string|array $status) => Beneficiary::where('created_by', $user->getKey())
            ->whereIn('status', (array) $status)
            ->count();

        // A file sent back carries the note that sent it (T-125), and it is
        // the one thing in this queue that is waiting on a correction rather
        // than on a first visit.
        $returned = Beneficiary::where('created_by', $user->getKey())
            ->where('status', 'pending_visit')
            ->whereNotNull('reject_reason_ar')
            ->count();

        return [
            Stat::make(__('sanabel.dashboard.my_drafts'), $mine('draft'))
                ->description(__('sanabel.dashboard.my_drafts_help'))
                ->color('gray')
                ->url($this->cases('draft', mine: true)),

            Stat::make(__('sanabel.dashboard.my_awaiting_visit'), $mine('pending_visit'))
                ->description($returned > 0
                    ? __('sanabel.dashboard.my_returned', ['count' => $returned])
                    : __('sanabel.dashboard.my_awaiting_visit_help'))
                ->color($returned > 0 ? 'warning' : 'info')
                ->url($this->cases('pending_visit', mine: true)),

            Stat::make(__('sanabel.dashboard.my_awaiting_supervisor'), $mine('verified'))
                ->description(__('sanabel.dashboard.awaiting_supervisor_help'))
                ->color('info')
                ->url($this->cases('verified', mine: true)),

            Stat::make(__('sanabel.dashboard.my_awaiting_approval'), $mine('pending_approval'))
                ->description(__('sanabel.dashboard.my_awaiting_approval_help'))
                ->color('warning')
                ->url($this->cases('pending_approval', mine: true)),

            Stat::make(__('sanabel.dashboard.my_published'), $mine('published'))
                ->description(__('sanabel.dashboard.published_help'))
                ->color('success')
                ->url($this->cases('published', mine: true)),

            Stat::make(__('sanabel.dashboard.my_needs_reassessment'), $mine('needs_reassessment'))
                ->description(__('sanabel.dashboard.my_needs_reassessment_help'))
                ->color('danger')
                ->url($this->cases('needs_reassessment', mine: true)),

            Stat::make(__('sanabel.dashboard.my_rejected'), $mine('rejected'))
                ->description(__('sanabel.dashboard.my_rejected_help'))
                ->color('danger')
                ->url($this->cases('rejected', mine: true)),
        ];
    }

    /**
     * The association's own queues. Every count here reads the same rows the
     * lists do, so a region-scoped account sees its own region and nothing
     * else without this screen having to say so.
     *
     * @return array<int,Stat>
     */
    private function reviewQueues(bool $mine = false): array
    {
        // «ضمن منطقتك» under each figure, for a reader whose lists are their
        // own region and whose figures therefore are too.
        $where = fn (?string $help = null) => $mine
            ? trim(($help ? $help.' — ' : '').__('sanabel.dashboard.in_your_area'))
            : $help;

        return [
            Stat::make(__('sanabel.dashboard.published_cases'), Beneficiary::published()->count())
                ->description($where(__('sanabel.dashboard.published_help')))
                ->color('success')
                ->url($this->cases('published')),

            // The three sign-offs, each counted where it actually waits. One
            // card lumping them together said nothing about who was holding up
            // which file.
            Stat::make(__('sanabel.dashboard.awaiting_delegate'),
                Beneficiary::whereIn('status', CaseService::AWAITING_FIELD)->count())
                ->description($where(__('sanabel.dashboard.awaiting_delegate_help')))
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
                ->description($where(__('sanabel.dashboard.awaiting_supervisor_help')))
                ->color('info')
                ->url(ApprovalFollowUp::getUrl()),

            Stat::make(__('sanabel.dashboard.pending_approval'),
                Beneficiary::where('status', 'pending_approval')->count())
                ->description($where())
                ->color('warning')
                ->url($this->cases('pending_approval')),

            Stat::make(__('sanabel.dashboard.needs_reassessment'),
                Beneficiary::where('status', 'needs_reassessment')->count())
                ->description($where())
                ->color('danger')
                ->url($this->cases('needs_reassessment')),
        ];
    }

    /**
     * The money. Association-wide by nature — a donation is not attached to a
     * region — so it is shown to the accounts whose reports are the
     * association's own.
     *
     * @return array<int,Stat>
     */
    private function money(): array
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
