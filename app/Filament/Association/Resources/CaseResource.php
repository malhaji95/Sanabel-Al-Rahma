<?php

namespace App\Filament\Association\Resources;

use App\Filament\Association\Resources\CaseResource\Pages;
use App\Models\Beneficiary;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** T-17 — an association sees its own and referred cases, and nothing else. */
class CaseResource extends Resource
{
    protected static ?string $model = Beneficiary::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $slug = 'cases';

    public static function getModelLabel(): string
    {
        return __('sanabel.beneficiary.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.beneficiary.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->where(function (Builder $query) use ($user) {
            $query->where('created_by', $user->getKey());

            if ($user->association_id) {
                $query->orWhere('created_by', $user->association_id);
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('file_number')->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('family_name')->label(__('sanabel.beneficiary.family_name'))->searchable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.status.'.$state)),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCases::route('/'),
            'view' => Pages\ViewCase::route('/{record}'),
        ];
    }
}
