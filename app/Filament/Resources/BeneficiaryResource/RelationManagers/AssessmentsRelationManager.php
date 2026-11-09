<?php

namespace App\Filament\Resources\BeneficiaryResource\RelationManagers;

use App\Models\Assessment;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * T-278 — «توضيح كيف تُحفظ الدراسات السابقة والإصدارات والتعديلات ومن
 * اعتمدها», 9 November.
 *
 * Every answer to that question was already in the database and none of it was
 * on a screen. A study is never overwritten: a new one is written beside it and
 * the old row becomes `superseded`, carrying its own figures, its own snapshot
 * of the reference values and weights it was computed with, who computed it,
 * who approved it and when. The file showed the last one only, so the history
 * existed and nobody could read it.
 *
 * Nothing is written here. There is no create button, no edit and no delete:
 * a study is produced by the engine and approved through the case decision,
 * not typed into a table — and rule 8 is the reason the old rows are worth
 * keeping at all.
 */
class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.assessment_history.title');
    }

    public static function getModelLabel(): ?string
    {
        return __('sanabel.assessment_history.singular');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('sanabel.assessment_history.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['creator', 'approver'])->latest('id'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('sanabel.assessment_history.none'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('sanabel.assessment_history.computed_at'))
                    ->dateTime('Y-m-d H:i'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.assessment_history.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.assessment_history.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'superseded' => 'gray',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('monthly_need')
                    ->label(__('sanabel.assessment_history.need'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('stable_income')
                    ->label(__('sanabel.assessment_history.income'))
                    ->numeric(),

                // The deficit is the need less the stable income: the figure
                // the funding percentage is taken of. It is not stored,
                // because it is those two columns and nothing else.
                Tables\Columns\TextColumn::make('deficit')
                    ->label(__('sanabel.assessment_history.deficit'))
                    ->state(fn (Assessment $record) => max(0, $record->monthly_need - $record->stable_income))
                    ->numeric(),

                // And the gap is what is left of the deficit after this
                // month's money, which is a different thing again.
                Tables\Columns\TextColumn::make('gap')
                    ->label(__('sanabel.assessment_history.gap'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('base_score')
                    ->label(__('sanabel.assessment_history.score'))
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label(__('sanabel.assessment_history.computed_by'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('approver.name')
                    ->label(__('sanabel.assessment_history.approved_by'))
                    ->placeholder('—')
                    ->description(fn (Assessment $record) => $record->approved_at?->format('Y-m-d H:i')),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label(__('sanabel.assessment_history.explain'))
                    ->modalHeading(__('sanabel.assessment_history.explain'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('sanabel.actions.close'))
                    ->form([
                        Forms\Components\ViewField::make('need_breakdown')
                            ->view('filament.forms.need-breakdown')
                            ->columnSpanFull()
                            ->dehydrated(false),

                        Forms\Components\ViewField::make('score_breakdown')
                            ->view('filament.forms.score-breakdown')
                            ->columnSpanFull()
                            ->dehydrated(false),
                    ]),
            ])
            ->bulkActions([]);
    }
}
