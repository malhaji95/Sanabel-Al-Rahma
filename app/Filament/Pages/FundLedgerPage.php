<?php

namespace App\Filament\Pages;

use App\Models\Fund;
use App\Services\FundLedger;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * T-324 — phase two, item 9: «السجلّ المالي».
 *
 * Every movement of one fund, in date order, with the balance after each. It
 * reads the four tables the money already lives in and keeps no ledger of its
 * own: a ledger kept beside the money is a second truth to keep in step.
 */
class FundLedgerPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static string $view = 'filament.pages.fund-ledger';

    public ?string $fundKey = null;

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public function getTitle(): string
    {
        return __('sanabel.ledger.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.ledger.title');
    }

    /**
     * The accounts that answer for the money, and the ones that oversee them.
     *
     * Not `view_reports`: almost every role holds that key, scoped to its own
     * work, and this page is every movement of a whole fund — the same reason
     * the permission matrix and the audit log refuse to gate on it. A donor
     * holds it and has no business here.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can_('reconcile_disbursement')
            || $user?->can_('verify_payment')
            || $user?->isAdmin()
            || $user?->hasRole('council', 'board_director', 'oversight_director', 'executive_director'));
    }

    public function mount(): void
    {
        $this->fundKey ??= Fund::OPERATIONAL;
    }

    /** @return Collection<int,Fund> */
    public function funds()
    {
        return Fund::orderBy('id')->get();
    }

    public function fund(): ?Fund
    {
        return Fund::where('key', $this->fundKey)->first();
    }

    /** @return array<int,array<string,mixed>> */
    public function movements(): array
    {
        $fund = $this->fund();

        return $fund ? app(FundLedger::class)->for($fund) : [];
    }

    public function balance(): int
    {
        $fund = $this->fund();

        return $fund ? app(FundLedger::class)->balance($fund) : 0;
    }

    public function showFund(string $key): void
    {
        $this->fundKey = $key;
    }
}
