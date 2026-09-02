<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegionRentReferenceResource\Pages;
use App\Models\RegionRentReference;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Versioned. A new row supersedes the old one; past assessments keep their snapshot. */
class RegionRentReferenceResource extends Resource
{
    protected static ?string $model = RegionRentReference::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.rent_reference.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.rent_reference.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('region_id')->label(__('sanabel.beneficiary.region'))->relationship('region', 'name_ar')->searchable()->required(),
            Forms\Components\Select::make('family_size_band')->label(__('sanabel.rent_reference.band'))->options(['1-3' => '1-3', '4-6' => '4-6', '7+' => '7+'])->required(),
            Forms\Components\TextInput::make('reference_rent')->label(__('sanabel.rent_reference.amount'))->numeric()->minValue(0)->required()->suffix(config('sanabel.currency')),
            Forms\Components\DatePicker::make('effective_from')->label(__('sanabel.reference.effective_from'))->required(),
            Forms\Components\TextInput::make('version')->label(__('sanabel.reference.version'))->numeric()->minValue(0)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('family_size_band')->label(__('sanabel.rent_reference.band'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('reference_rent')->label(__('sanabel.rent_reference.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('effective_from')->label(__('sanabel.reference.effective_from'))->date()->sortable(),
                Tables\Columns\TextColumn::make('version')->label(__('sanabel.reference.version'))->numeric()->sortable(),
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
            'index' => Pages\ListRegionRentReferences::route('/'),
            'create' => Pages\CreateRegionRentReference::route('/create'),
            'edit' => Pages\EditRegionRentReference::route('/{record}/edit'),
        ];
    }
}
