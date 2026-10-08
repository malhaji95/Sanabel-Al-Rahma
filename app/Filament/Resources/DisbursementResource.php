<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DisbursementResource\Pages;
use App\Models\Beneficiary;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * The first step of the money path: the case officer confirms what a family
 * is owed this month. The treasurer then gathers confirmed lines into one
 * order from the bulk action at the foot of this list.
 */
class DisbursementResource extends Resource
{
    protected static ?string $model = Disbursement::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.disbursement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.disbursement.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('beneficiary_id')
                ->label(__('sanabel.beneficiary.singular'))
                ->relationship('beneficiary', 'file_number')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Forms\Set $set, $state) {
                    $family = Beneficiary::find($state);

                    $set('remaining_hint', $family
                        ? number_format(app(DisbursementService::class)
                            ->remainingForPeriod($family, now()->startOfMonth()))
                        : null);
                }),

            Forms\Components\Placeholder::make('remaining_hint')
                ->label(__('sanabel.disbursement.remaining_this_month'))
                ->content(fn (Forms\Get $get) => $get('remaining_hint') ?? '—'),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.disbursement.amount'))
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText(__('sanabel.disbursement.amount_help')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.disbursement.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('period')
                    ->label(__('sanabel.disbursement.period'))->date('Y-m')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.disbursement.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'reconciled', 'executed' => 'success',
                        'approved' => 'info',
                        'in_order' => 'warning',
                        'rejected', 'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('order.reference_no')
                    ->label(__('sanabel.disbursement.reference_no'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('confirmedBy.name')
                    ->label(__('sanabel.disbursement.confirmed_by'))->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(collect(Disbursement::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __('sanabel.disbursement.statuses.'.$s)])
                        ->all()),
            ])
            ->bulkActions([
                // The treasurer's step: gather confirmed lines into one order.
                Tables\Actions\BulkAction::make('gather')
                    ->label(__('sanabel.disbursement.gather'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->visible(fn () => auth()->user()?->can_('raise_disbursement_order'))
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $confirmed = $records->where('status', 'confirmed');

                        if ($confirmed->isEmpty()) {
                            Notification::make()
                                ->title(__('sanabel.disbursement.only_confirmed_join'))
                                ->danger()->send();

                            return;
                        }

                        $order = app(DisbursementService::class)
                            ->gather($confirmed, auth()->user());

                        Notification::make()
                            ->title(__('sanabel.disbursement.gathered', [
                                'ref' => $order->reference_no,
                                'count' => $confirmed->count(),
                            ]))
                            ->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisbursements::route('/'),
            'create' => Pages\CreateDisbursement::route('/create'),
        ];
    }
}
