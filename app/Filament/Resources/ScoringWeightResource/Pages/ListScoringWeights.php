<?php

namespace App\Filament\Resources\ScoringWeightResource\Pages;

use App\Filament\Resources\ScoringWeightResource;
use App\Models\ScoringWeight;
use App\Services\ReferenceResolver;
use App\Services\WeightRollback;
use App\Services\WeightSimulation;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListScoringWeights extends ListRecords
{
    protected static string $resource = ScoringWeightResource::class;

    /** A difference this small is rounding, not a decision. */
    private const EPSILON = 0.005;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            /*
             | T-315 — «التراجع عن إصدار», phase two, item 14.
             |
             | The screen published a new version and never went back. Whoever
             | published a wrong seven this morning had one way out: read
             | yesterday's numbers off the audit log and type them in again by
             | hand, seven times.
             |
             | It is a publish, not an undo: the weights of the named date go
             | out as a new version in force from today, and no past row is
             | touched. A study already computed keeps its own snapshot
             | (hard rule 8), so this changes what happens next and never what
             | has already happened.
             */
            Actions\Action::make('rollback')
                ->label(__('sanabel.weight.rollback'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => auth()->user()?->can('create', ScoringWeight::class))
                ->form([
                    Forms\Components\DatePicker::make('as_of')
                        ->label(__('sanabel.weight.rollback_date'))
                        ->helperText(__('sanabel.weight.rollback_date_help'))
                        ->maxDate(now())
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    $asOf = Carbon::parse($data['as_of']);

                    try {
                        $changed = app(WeightRollback::class)->restoreTo($asOf, auth()->user());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('sanabel.weight.rollback_done', [
                            'date' => $asOf->toDateString(),
                            'count' => implode('، ', array_keys($changed)),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * T-308 — «هل النظام يتعامل مع الرقم ٨٥ باعتباره ٨٥٪, أم يقوم بقسمة كل
     * وزن على مجموع الأوزان؟», 17 November.
     *
     * It does neither: it multiplies the factor by the number as written and
     * adds the seven up, so a weight of 85 against factors already out of a
     * hundred drives every file to the ceiling and flattens the ranking. The
     * screen said nothing about that, and the association was a save away
     * from it.
     *
     * So the screen states the arithmetic, and reads back the sum in force.
     * It does not refuse a sum other than one: the weights are the
     * association's decision, and the simulator exists to try one out. It
     * only makes sure nobody reaches that decision by accident.
     */
    public function getSubheading(): ?string
    {
        $values = app(ReferenceResolver::class)->weights(Carbon::now())['values'];

        $sum = 0.0;

        foreach (WeightSimulation::FACTORS as $key) {
            $sum += (float) ($values[$key] ?? 0.0);
        }

        $shown = number_format($sum, 2);

        return __('sanabel.weight.scales').' '.(abs($sum - 1.0) <= self::EPSILON
            ? __('sanabel.weight.sum_ok', ['sum' => $shown])
            : __('sanabel.weight.sum_off', ['sum' => $shown]));
    }
}
