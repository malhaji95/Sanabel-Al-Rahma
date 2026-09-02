<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegionRateResource\Pages;
use App\Models\RegionRate;
use App\Services\ReferenceImporter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-03 — living reference values per region, versioned, with a bulk import.
 * Editing a value writes a new version; assessments already stored keep theirs.
 */
class RegionRateResource extends Resource
{
    protected static ?string $model = RegionRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.reference');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.rate.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.rate.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('region_id')
                ->label(__('sanabel.beneficiary.region'))
                ->relationship('region', 'name_ar')->searchable()->required(),

            Forms\Components\Select::make('person_class')
                ->label(__('sanabel.member.person_class'))
                ->options(__('sanabel.person_class'))->required(),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.rate.amount'))
                ->numeric()->minValue(0)->required()
                ->suffix(config('sanabel.currency')),

            Forms\Components\DatePicker::make('effective_from')
                ->label(__('sanabel.reference.effective_from'))
                ->default(now())->required(),

            Forms\Components\TextInput::make('version')
                ->label(__('sanabel.reference.version'))
                ->numeric()->minValue(1)
                ->default(fn () => 1 + (int) RegionRate::max('version'))
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('person_class')
                    ->label(__('sanabel.member.person_class'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.person_class.'.$state))
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')->label(__('sanabel.rate.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('effective_from')->label(__('sanabel.reference.effective_from'))->date()->sortable(),
                Tables\Columns\TextColumn::make('version')->label(__('sanabel.reference.version'))->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('region_id')
                    ->label(__('sanabel.beneficiary.region'))
                    ->relationship('region', 'name_ar'),
                Tables\Filters\SelectFilter::make('person_class')
                    ->label(__('sanabel.member.person_class'))
                    ->options(__('sanabel.person_class')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('import')
                    ->label(__('sanabel.actions.import'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label(__('sanabel.reference.import_file'))
                            ->helperText(__('sanabel.reference.import_help'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        try {
                            $result = app(ReferenceImporter::class)
                                ->importRates($data['file']->getRealPath(), auth()->id());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('sanabel.actions.imported'))
                            ->body(__('sanabel.reference.import_result', [
                                'imported' => $result['imported'],
                                'skipped' => count($result['skipped']),
                            ]))
                            ->success()->send();
                    }),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRegionRates::route('/'),
            'create' => Pages\CreateRegionRate::route('/create'),
            'edit' => Pages\EditRegionRate::route('/{record}/edit'),
        ];
    }
}
