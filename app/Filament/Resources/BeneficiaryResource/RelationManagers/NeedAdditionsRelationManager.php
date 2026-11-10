<?php

namespace App\Filament\Resources\BeneficiaryResource\RelationManagers;

use App\Models\NeedAddition;
use App\Services\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * T-289 — the half that was missing.
 *
 * The engine has accepted an approved addition to a household's need since it
 * was written, and no screen ever passed one. So the association could record
 * a medical cost or a debt — both of which raise the priority score and add no
 * money, exactly as they asked — and then had no way to decide to pay towards
 * it. This is that decision, and it looks like one: an amount, what it is for
 * in words, who approved it, and the months it runs.
 */
class NeedAdditionsRelationManager extends RelationManager
{
    protected static string $relationship = 'needAdditions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.need_addition.plural');
    }

    public static function getModelLabel(): ?string
    {
        return __('sanabel.need_addition.singular');
    }

    public static function getPluralModelLabel(): ?string
    {
        return __('sanabel.need_addition.plural');
    }

    /** Only the hands that approve a case approve money onto it. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can_('view_full_case');
    }

    private static function mayApprove(): bool
    {
        return (bool) auth()->user()?->can_('approve_case');
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label(__('sanabel.need_addition.kind'))
                ->options(__('sanabel.need_addition.kinds'))
                ->required(),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.need_addition.amount'))
                ->numeric()->minValue(1)->required()
                ->suffix(Money::short()),

            Forms\Components\Textarea::make('reason_ar')
                ->label(__('sanabel.need_addition.reason'))
                ->helperText(__('sanabel.need_addition.reason_help'))
                ->rows(2)->required()
                ->columnSpanFull(),

            Forms\Components\DatePicker::make('starts_on')
                ->label(__('sanabel.need_addition.starts_on'))
                ->displayFormat('Y-m')->format('Y-m-01')
                ->default(now()->startOfMonth())
                ->required(),

            Forms\Components\DatePicker::make('ends_on')
                ->label(__('sanabel.need_addition.ends_on'))
                ->helperText(__('sanabel.need_addition.ends_on_help'))
                ->displayFormat('Y-m')->format('Y-m-01')
                ->afterOrEqual('starts_on'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('approver')->latest('id'))
            ->emptyStateHeading(__('sanabel.need_addition.none'))
            ->description(__('sanabel.need_addition.intro'))
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('sanabel.need_addition.kind'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.need_addition.kinds.'.$state)),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.need_addition.amount'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('reason_ar')
                    ->label(__('sanabel.need_addition.reason'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('starts_on')
                    ->label(__('sanabel.need_addition.starts_on'))
                    ->date('Y-m'),

                Tables\Columns\TextColumn::make('ends_on')
                    ->label(__('sanabel.need_addition.ends_on'))
                    ->date('Y-m')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('approver.name')
                    ->label(__('sanabel.need_addition.approved_by'))
                    ->placeholder(__('sanabel.need_addition.pending'))
                    ->description(fn (NeedAddition $record) => $record->approved_at?->format('Y-m-d H:i')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn () => self::mayApprove()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // An approved amount is not edited into another amount:
                    // it is stopped and a new one is approved, so the file
                    // keeps what was decided and when.
                    ->visible(fn (NeedAddition $record) => self::mayApprove() && $record->approved_at === null),

                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.need_addition.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.need_addition.approve_help'))
                    ->visible(fn (NeedAddition $record) => self::mayApprove() && $record->approved_at === null)
                    ->action(function (NeedAddition $record) {
                        $record->update([
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);

                        Notification::make()->success()
                            ->title(__('sanabel.need_addition.approved'))->send();
                    }),

                Tables\Actions\Action::make('stop')
                    ->label(__('sanabel.need_addition.stop'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.need_addition.stop_help'))
                    ->visible(fn (NeedAddition $record) => self::mayApprove()
                        && $record->approved_at !== null
                        && ($record->ends_on === null || $record->ends_on->isFuture()))
                    ->action(function (NeedAddition $record) {
                        $record->update(['ends_on' => now()->startOfMonth()->subMonth()]);

                        Notification::make()->success()
                            ->title(__('sanabel.need_addition.stopped'))->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
