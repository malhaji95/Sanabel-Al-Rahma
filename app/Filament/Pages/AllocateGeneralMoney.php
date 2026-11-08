<?php

namespace App\Filament\Pages;

use App\Models\Beneficiary;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\CoverageService;
use App\Services\GeneralMoneyService;
use App\Services\Money;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Where general money is placed on families. Earmarked money never appears
 * here — it is already tied to the files the donor named.
 */
class AllocateGeneralMoney extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static string $view = 'filament.pages.allocate-general-money';

    protected static ?int $navigationSort = 45;

    public ?int $donationId = null;

    /*
     | T-263 — the doors' money on the same screen as the general money.
     |
     | Two balances, one rule: nothing is spent that has not arrived. The
     | difference is only where it is drawn from — general money is picked by
     | the donation it came in on, and a door's is drawn from the door's own
     | balance, oldest gift first, because the donor chose the door and which
     | transfer it rode in on is bookkeeping.
     |
     | Choosing one clears the other: an operator who has a door open and
     | then picks a general donation meant to leave the door.
     */
    public ?int $doorId = null;

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.general.title');
    }

    public function getTitle(): string
    {
        return __('sanabel.general.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can_('manage_distribution') ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->form->fill(['coverage_month' => app(CoverageService::class)->currentMonth()->format('Y-m')]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('beneficiary_id')
                    ->label(__('sanabel.general.to_case'))
                    ->options(fn () => Beneficiary::query()
                        ->whereIn('status', ['approved', 'published'])
                        ->orderBy('file_number')
                        // File number only: this screen is about money, and the
                        // family behind it is not what the operator chooses by.
                        ->pluck('file_number', 'id'))
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('coverage_month')
                    ->label(__('sanabel.general.coverage_month'))
                    ->options(fn () => collect(app(CoverageService::class)->openMonths())
                        ->mapWithKeys(fn (Carbon $m) => [$m->format('Y-m') => $m->translatedFormat('F Y')])
                        ->all())
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label(__('sanabel.general.amount'))
                    ->numeric()
                    ->minValue(1)
                    ->suffix(Money::short())
                    ->required(),
            ])
            ->columns(3)
            ->statePath('data');
    }

    public function allocate(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();
        $donation = Donation::findOrFail($this->donationId);
        $case = Beneficiary::withoutGlobalScopes()->findOrFail($state['beneficiary_id']);

        try {
            app(GeneralMoneyService::class)->allocate(
                $donation,
                $case,
                (int) $state['amount'],
                Carbon::createFromFormat('Y-m', $state['coverage_month'])->startOfMonth(),
                auth()->user(),
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->donationId = null;
        $this->form->fill(['coverage_month' => app(CoverageService::class)->currentMonth()->format('Y-m')]);

        Notification::make()->title(__('sanabel.general.allocated'))->success()->send();
    }

    public function select(int $donationId): void
    {
        $this->donationId = $donationId;
        $this->doorId = null;
    }

    public function selectDoor(int $doorId): void
    {
        $this->doorId = $doorId;
        $this->donationId = null;
    }

    public function allocateFromDoor(): void
    {
        abort_unless(self::canAccess(), 403);

        $state = $this->form->getState();
        $door = Campaign::findOrFail($this->doorId);
        $case = Beneficiary::withoutGlobalScopes()->findOrFail($state['beneficiary_id']);

        try {
            app(GeneralMoneyService::class)->allocateFromDoor(
                $door,
                $case,
                (int) $state['amount'],
                Carbon::createFromFormat('Y-m', $state['coverage_month'])->startOfMonth(),
                auth()->user(),
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->doorId = null;
        $this->form->fill(['coverage_month' => app(CoverageService::class)->currentMonth()->format('Y-m')]);

        Notification::make()->title(__('sanabel.general.allocated'))->success()->send();
    }

    protected function getViewData(): array
    {
        $service = app(GeneralMoneyService::class);

        return [
            'donations' => $service->undistributed()->map(fn (Donation $d) => [
                'id' => $d->id,
                'transaction_ref' => $d->transaction_ref,
                'donor' => $d->donor?->name_ar,
                'amount' => $d->amount,
                'remaining' => $service->remaining($d),
                'verified_at' => $d->verified_at?->translatedFormat('Y-m-d'),
            ]),
            'balance' => $service->balance(),
            /*
             | Only the doors that are actually holding something. A list of
             | every door, most of them at zero, is a list nobody reads.
             */
            'doors' => Campaign::query()
                ->whereNull('beneficiary_id')
                ->orderBy('reference_no')
                ->get()
                ->map(fn (Campaign $door) => [
                    'id' => $door->id,
                    'reference_no' => $door->reference_no,
                    'title' => $door->title_ar,
                    'balance' => $service->doorBalance($door),
                ])
                ->filter(fn (array $door) => $door['balance'] > 0)
                ->values(),
        ];
    }
}
