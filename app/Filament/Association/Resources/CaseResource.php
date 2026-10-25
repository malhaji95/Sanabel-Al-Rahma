<?php

namespace App\Filament\Association\Resources;

use App\Filament\Association\Resources\CaseResource\Pages;
use App\Filament\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\Scopes\RegionScope;
use Filament\Forms\Form;
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

    /**
     * T-144 — the files this association registered, and nothing else.
     *
     * «هذي الجمعية ليش تظهر اسماء مستفيدين وهو ماضاف شي؟», 28 October. It
     * used to list every household in the association's city, by name,
     * including the ones another body had registered. docs/04-permissions.md
     * rule 3 says its own and referred cases only, and that is what this is
     * now; the city-wide awareness lives in the coordination lookup, which
     * carries no name.
     *
     * The region scope is lifted deliberately: a file this association
     * registered stays its own even if the household later moved to another
     * area.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->withoutGlobalScope(RegionScope::class)
            ->where(function (Builder $query) use ($user) {
                $query->where('created_by', $user->getKey());

                if ($user->association_id) {
                    $query->orWhere('created_by', $user->association_id);
                }
            });
    }

    /** The same registration form the association's own researchers fill. */
    public static function form(Form $form): Form
    {
        return BeneficiaryResource::form($form);
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
            ->actions([
                Tables\Actions\ViewAction::make(),
                // While it is still a draft: after the hand-over the file
                // belongs to the field, and a change to it goes through a
                // change request like anybody else's.
                Tables\Actions\EditAction::make()
                    ->visible(fn (Beneficiary $record) => auth()->user()->can('update', $record)),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCases::route('/'),
            // T-144 — «دورها من التسجيل كفقير»: the association's part starts
            // here, and it had no screen for it.
            'create' => Pages\CreateCase::route('/create'),
            'view' => Pages\ViewCase::route('/{record}'),
            'edit' => Pages\EditCase::route('/{record}/edit'),
        ];
    }
}
