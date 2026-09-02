<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DistributionResource\Pages;
use App\Models\Distribution;
use App\Services\DistributionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** T-28 — generate, review, approve (freezing the list), execute, prove. */
class DistributionResource extends Resource
{
    protected static ?string $model = Distribution::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.distribution.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.distribution.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.distribution.title'))->required(),

            Forms\Components\Select::make('region_id')
                ->label(__('sanabel.beneficiary.region'))
                ->relationship('region', 'name_ar')->searchable()->required(),

            Forms\Components\TextInput::make('per_family_amount')
                ->label(__('sanabel.distribution.per_family'))
                ->numeric()->minValue(1)->required()
                ->suffix(config('sanabel.currency')),

            Forms\Components\Select::make('criteria_json.support_type')
                ->label(__('sanabel.beneficiary.support_type'))
                ->options(__('sanabel.masked.need_type'))
                ->default('monthly')->required(),

            Forms\Components\TextInput::make('criteria_json.limit')
                ->label(__('sanabel.distribution.limit'))
                ->numeric()->minValue(1)->default(100)->required(),

            // Read-only once frozen — the list is never regenerated after approval.
            Forms\Components\Placeholder::make('frozen')
                ->label(__('sanabel.distribution.frozen'))
                ->content(fn (?Distribution $record) => $record?->isFrozen() ? __('sanabel.yes') : __('sanabel.no')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.distribution.title'))->searchable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region')),
                Tables\Columns\TextColumn::make('per_family_amount')->label(__('sanabel.distribution.per_family'))->numeric(),
                Tables\Columns\TextColumn::make('total_amount')->label(__('sanabel.distribution.total'))->numeric(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'partial' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('items_count')->counts('items')->label(__('sanabel.distribution.items')),
            ])
            ->actions([
                Tables\Actions\Action::make('generate')
                    ->label(__('sanabel.actions.generate_list'))
                    ->icon('heroicon-o-list-bullet')
                    ->visible(fn (Distribution $record) => $record->status === 'draft')
                    ->action(function (Distribution $record) {
                        $generated = app(DistributionService::class)->generateList($record);

                        Notification::make()
                            ->title(__('sanabel.distribution.generated', ['count' => count($generated->list_json ?? [])]))
                            ->success()->send();
                    }),

                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.actions.freeze_and_approve'))
                    ->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (Distribution $record) => $record->status === 'draft' && filled($record->list_json))
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.distribution.freeze_warning'))
                    ->action(function (Distribution $record) {
                        app(DistributionService::class)->approve($record, auth()->user());

                        Notification::make()->title(__('sanabel.actions.approved'))->success()->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [DistributionResource\RelationManagers\ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDistributions::route('/'),
            'create' => Pages\CreateDistribution::route('/create'),
            'edit' => Pages\EditDistribution::route('/{record}/edit'),
        ];
    }
}
