<?php

namespace App\Filament\Pages;

use App\Models\Basket;
use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Disbursement;
use App\Models\DistributionItem;
use App\Models\Donation;
use App\Models\DonationAllocation;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Services\CoverageService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * The operational reports the association listed: what was collected, what
 * was earmarked, what went out, what is still held or waiting, the
 * sponsorships, what failed, how the families are covered, and the
 * campaigns.
 *
 * Figures for one month, read from the same tables the screens read. Nothing
 * here is an analysis; it is the count, so the association stops exporting a
 * list to arrive at a number it already owns.
 */
class OperationalReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.operational-reports';

    public ?string $month = null;

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public function getTitle(): string
    {
        return __('sanabel.reports.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.reports.title');
    }

    /**
     * These are the association's figures, not a user's own. Almost every
     * role holds `view_reports`, but a donor's and a provider's is scoped to
     * their own work, so the scope is what decides here rather than the key.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user?->can_('view_reports')) {
            return false;
        }

        return in_array(
            app(\App\Services\PermissionService::class)->scopeFor($user, 'view_reports'),
            ['all', 'area'],
            true,
        );
    }

    public function mount(): void
    {
        $this->month = now()->startOfMonth()->toDateString();
        $this->form->fill(['month' => $this->month]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('month')
                ->label(__('sanabel.reports.month'))
                ->options($this->monthOptions())
                ->live()
                ->afterStateUpdated(fn ($state) => $this->month = $state),
        ]);
    }

    /** @return array<string,string> the last twelve months, newest first. */
    private function monthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(function (int $back) {
                $month = now()->startOfMonth()->subMonthsNoOverflow($back);

                return [$month->toDateString() => $month->translatedFormat('F Y')];
            })
            ->all();
    }

    private function from(): Carbon
    {
        return Carbon::parse($this->month ?? now()->startOfMonth())->startOfMonth();
    }

    /** @return array<string,array<string,int|string>> */
    public function getReport(): array
    {
        $from = $this->from();
        $to = $from->copy()->endOfMonth();

        return [
            'money' => $this->money($from, $to),
            'sponsorships' => $this->sponsorships(),
            'failures' => $this->failures($from, $to),
            'families' => $this->families(),
            'campaigns' => $this->campaigns(),
        ];
    }

    private function money(Carbon $from, Carbon $to): array
    {
        $verified = fn (?string $designation = null) => (int) Donation::query()
            ->where('status', 'verified')
            ->whereNull('reversal_of_id')
            ->whereBetween('verified_at', [$from, $to])
            ->when($designation, fn ($q) => $q->where('designation', $designation))
            ->sum('amount');

        return [
            'collected' => $verified(),
            'earmarked' => $verified('earmarked'),
            'general' => $verified('general'),
            // What the association actually tied to named families this month.
            'allocated' => (int) DonationAllocation::query()
                ->join('donations', 'donations.id', '=', 'donation_allocations.donation_id')
                ->where('donations.status', 'verified')
                ->whereNull('donations.reversal_of_id')
                ->whereNull('donations.deleted_at')
                ->whereBetween('donations.verified_at', [$from, $to])
                ->sum('donation_allocations.amount'),
            // What left through an approved disbursement order.
            'disbursed' => (int) Disbursement::query()
                ->whereIn('status', ['executed', 'received', 'reconciled'])
                ->whereBetween('executed_at', [$from, $to])
                ->sum('amount'),
            'pending' => (int) Donation::where('status', 'pending')->sum('amount'),
            'reserved' => (int) Basket::where('status', 'reserved')
                ->where('reserved_until', '>', now())
                ->withSum('items', 'amount')
                ->get()
                ->sum('items_sum_amount'),
            'reversed' => (int) Donation::query()
                ->whereNotNull('reversal_of_id')
                ->whereBetween('verified_at', [$from, $to])
                ->sum('amount'),
        ];
    }

    private function sponsorships(): array
    {
        return [
            'active' => Sponsorship::where('status', 'active')->count(),
            'lapsed' => Sponsorship::where('status', 'lapsed')->count(),
            'due' => SponsorshipInstallment::where('status', 'due')->count(),
            'overdue' => SponsorshipInstallment::where('status', 'overdue')->count(),
        ];
    }

    private function failures(Carbon $from, Carbon $to): array
    {
        return [
            'distribution_items' => DistributionItem::where('status', 'failed')->count(),
            'disbursements' => Disbursement::where('status', 'failed')->count(),
            'disputed' => Disbursement::where('status', 'disputed')->count(),
            'rejected_donations' => Donation::where('status', 'rejected')
                ->whereBetween('updated_at', [$from, $to])->count(),
        ];
    }

    private function families(): array
    {
        $coverage = app(CoverageService::class);
        $published = Beneficiary::published()->get();
        $funded = $coverage->fundedForMonthForMany($published, $this->from());

        $bands = ['complete' => 0, 'critical' => 0, 'middle' => 0, 'low' => 0];

        foreach ($published as $family) {
            $bands[$coverage->priorityBand($family, $funded[$family->getKey()] ?? 0, $this->from())]++;
        }

        return array_merge($bands, [
            'published' => $published->count(),
            'awaiting_approval' => Beneficiary::where('status', 'pending_approval')->count(),
            'needs_reassessment' => Beneficiary::where('status', 'needs_reassessment')->count(),
        ]);
    }

    private function campaigns(): array
    {
        return [
            'active' => Campaign::where('status', 'active')->count(),
            'funded' => Campaign::where('status', 'funded')->count(),
            'lapsed' => Campaign::where('status', 'lapsed')->count(),
            'goal' => (int) Campaign::whereIn('status', ['active', 'funded'])->sum('goal_amount'),
        ];
    }
}
