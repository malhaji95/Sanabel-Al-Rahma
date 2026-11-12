<?php

namespace App\Filament\Pages;

use App\Models\ScoringWeight;
use App\Services\WeightSimulation;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;

/**
 * T-297 — «توفير إمكانية اختبار أوزان جديدة دون تغيير نتائج الدراسات
 * المعتمدة», 12 November.
 *
 * The association asked to see what a change to the weights would do before
 * agreeing to it — the 85/15 model among others. The screen answers from the
 * figures each study already froze on its own row, so it reads and reckons
 * and writes nothing: no study is recomputed, none is touched, and leaving
 * the page leaves the system exactly as it was found.
 *
 * It sits beside the weights themselves and opens to the same people: a
 * proposal is read by whoever would be the one to enter it.
 */
class WeightSimulator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static string $view = 'filament.pages.weight-simulator';

    /** @var array<string,float> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public function getTitle(): string
    {
        return __('sanabel.simulator.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('sanabel.simulator.title');
    }

    /** Whoever may read the weights may try a set of them. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('viewAny', ScoringWeight::class);
    }

    public function mount(): void
    {
        $this->form->fill(app(WeightSimulation::class)->inForce());
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('sanabel.simulator.weights'))
                    ->description(__('sanabel.simulator.weights_help'))
                    ->schema([
                        Forms\Components\Grid::make(['default' => 2, 'lg' => 4])->schema(
                            collect(WeightSimulation::FACTORS)->map(
                                fn (string $key) => Forms\Components\TextInput::make($key)
                                    ->label(__('sanabel.weight.factors.'.$key).' — '.$key)
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->live(onBlur: true)
                            )->all()
                        ),
                    ]),
            ]);
    }

    /** The comparison the page draws, run fresh on whatever is in the boxes. */
    public function result(): array
    {
        return app(WeightSimulation::class)->run($this->data);
    }
}
