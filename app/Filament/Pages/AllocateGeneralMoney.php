<?php

namespace App\Filament\Pages;

use App\Models\Beneficiary;
use App\Models\Donation;
use App\Services\CoverageService;
use App\Services\GeneralMoneyService;
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
                    ->suffix(config('sanabel.currency'))
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
        ];
    }
}
