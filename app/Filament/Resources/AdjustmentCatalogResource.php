<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdjustmentCatalogResource\Pages;
use App\Models\AdjustmentCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AdjustmentCatalogResource extends Resource
{
    protected static ?string $model = AdjustmentCatalog::class;

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.adjustment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.adjustment.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('key')->label(__('sanabel.adjustment.key'))->required(),
            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.adjustment.name'))->required(),
            Forms\Components\TextInput::make('amount')->label(__('sanabel.adjustment.amount'))->numeric()->minValue(0)->required()->suffix(config('sanabel.currency')),
            Forms\Components\Select::make('region_id')->label(__('sanabel.beneficiary.region'))->relationship('region', 'name_ar')->searchable(),
            Forms\Components\DatePicker::make('effective_from')->label(__('sanabel.reference.effective_from'))->required(),
            Forms\Components\TextInput::make('version')->label(__('sanabel.reference.version'))->numeric()->minValue(0)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->label(__('sanabel.adjustment.key'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name_ar')->label(__('sanabel.adjustment.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->label(__('sanabel.adjustment.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('effective_from')->label(__('sanabel.reference.effective_from'))->date()->sortable(),
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
            'index' => Pages\ListAdjustmentCatalogs::route('/'),
            'create' => Pages\CreateAdjustmentCatalog::route('/create'),
            'edit' => Pages\EditAdjustmentCatalog::route('/{record}/edit'),
        ];
    }
}
