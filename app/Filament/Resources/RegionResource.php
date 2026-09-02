<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegionResource\Pages;
use App\Models\Region;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Region names are data, never code. An admin adds a region without a developer. */
class RegionResource extends Resource
{
    protected static ?string $model = Region::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.region.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.region.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.region.name'))->required(),
            Forms\Components\Select::make('type')->label(__('sanabel.region.type'))->options(__('sanabel.region_type'))->required(),
            Forms\Components\Select::make('parent_id')->label(__('sanabel.region.parent'))->relationship('parent', 'name_ar')->searchable(),
            Forms\Components\Toggle::make('is_active')->label(__('sanabel.region.is_active')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_ar')->label(__('sanabel.region.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->label(__('sanabel.region.type'))->badge(),
                Tables\Columns\TextColumn::make('parent.name_ar')->label(__('sanabel.region.parent'))->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('sanabel.region.is_active'))->boolean(),
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
            'index' => Pages\ListRegions::route('/'),
            'create' => Pages\CreateRegion::route('/create'),
            'edit' => Pages\EditRegion::route('/{record}/edit'),
        ];
    }
}
