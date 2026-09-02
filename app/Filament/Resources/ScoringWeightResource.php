<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScoringWeightResource\Pages;
use App\Models\ScoringWeight;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Weights are data. Editing them never changes an assessment already stored. */
class ScoringWeightResource extends Resource
{
    protected static ?string $model = ScoringWeight::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.weight.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.weight.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('factor_key')->label(__('sanabel.weight.factor'))->required(),
            Forms\Components\TextInput::make('weight')->label(__('sanabel.weight.value'))->numeric()->required(),
            Forms\Components\DatePicker::make('effective_from')->label(__('sanabel.reference.effective_from'))->required(),
            Forms\Components\TextInput::make('version')->label(__('sanabel.reference.version'))->numeric()->minValue(0)->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('factor_key')->label(__('sanabel.weight.factor'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('weight')->label(__('sanabel.weight.value'))->numeric()->sortable(),
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
            'index' => Pages\ListScoringWeights::route('/'),
            'create' => Pages\CreateScoringWeight::route('/create'),
            'edit' => Pages\EditScoringWeight::route('/{record}/edit'),
        ];
    }
}
