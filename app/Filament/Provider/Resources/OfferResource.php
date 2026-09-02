<?php

namespace App\Filament\Provider\Resources;

use App\Filament\Provider\Resources\OfferResource\Pages;
use App\Models\Provider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** A provider manages its own offers, and sees nothing else. */
class OfferResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $slug = 'offers';

    public static function getModelLabel(): string
    {
        return __('sanabel.provider.offer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.provider.offers');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.provider.name'))->disabled(),
            Forms\Components\TextInput::make('specialty_ar')->label(__('sanabel.provider.specialty')),
            Forms\Components\Select::make('discount_type')
                ->label(__('sanabel.provider.discount_type'))
                ->options(__('sanabel.discount_type'))->required(),
            Forms\Components\TextInput::make('discount_value')
                ->label(__('sanabel.provider.discount_value'))
                ->numeric()->minValue(0)->required(),
            Forms\Components\DatePicker::make('valid_until')->label(__('sanabel.provider.valid_until')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_ar')->label(__('sanabel.provider.name')),
                Tables\Columns\TextColumn::make('discount_value')->label(__('sanabel.provider.discount_value'))->numeric(),
                Tables\Columns\TextColumn::make('valid_until')->label(__('sanabel.provider.valid_until'))->date(),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOffers::route('/'),
            'edit' => Pages\EditOffer::route('/{record}/edit'),
        ];
    }
}
