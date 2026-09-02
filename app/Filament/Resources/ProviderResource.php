<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProviderResource\Pages;
use App\Models\Provider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.provider.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.provider.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.provider.name'))->required(),
            Forms\Components\Select::make('type')->label(__('sanabel.provider.type'))->options(__('sanabel.provider_type'))->required(),
            Forms\Components\TextInput::make('specialty_ar')->label(__('sanabel.provider.specialty')),
            Forms\Components\Select::make('region_id')->label(__('sanabel.beneficiary.region'))->relationship('region', 'name_ar')->searchable()->required(),
            Forms\Components\Select::make('discount_type')->label(__('sanabel.provider.discount_type'))->options(__('sanabel.discount_type'))->required(),
            Forms\Components\TextInput::make('discount_value')->label(__('sanabel.provider.discount_value'))->numeric()->minValue(0)->required(),
            Forms\Components\DatePicker::make('valid_until')->label(__('sanabel.provider.valid_until')),
            Forms\Components\Select::make('user_id')->label(__('sanabel.provider.account'))->relationship('user', 'name')->searchable(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_ar')->label(__('sanabel.provider.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label(__('sanabel.provider.type'))->badge(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('discount_value')->label(__('sanabel.provider.discount_value'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('valid_until')->label(__('sanabel.provider.valid_until'))->date()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'create' => Pages\CreateProvider::route('/create'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }
}
