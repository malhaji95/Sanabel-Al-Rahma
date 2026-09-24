<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegionRentReferenceResource\Pages;
use App\Models\RegionRentReference;
use App\Services\ReferenceImporter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
            Forms\Components\TextInput::make('reference_rent')
                ->label(__('sanabel.rent_reference.amount'))
                ->helperText(__('sanabel.reference.not_approved_help'))
                ->numeric()->minValue(0)
                ->suffix(config('sanabel.currency')),
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
                Tables\Columns\TextColumn::make('reference_rent')
                    ->label(__('sanabel.rent_reference.amount'))
                    ->placeholder(__('sanabel.reference.not_approved'))
                    ->numeric()->sortable(),
                Tables\Columns\TextColumn::make('effective_from')->label(__('sanabel.reference.effective_from'))->date()->sortable(),
                Tables\Columns\TextColumn::make('version')->label(__('sanabel.reference.version'))->numeric()->sortable(),
            ])
            // The same pair as the rates screen: take the template, fill it in,
            // bring it back. Rent references were edit-one-row-at-a-time before.
            ->headerActions([
                Tables\Actions\Action::make('template')
                    ->label(__('sanabel.actions.download_template'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => response()->streamDownload(
                        fn () => print (app(ReferenceImporter::class)->rentTemplate()),
                        'rent-references-template.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    )),

                Tables\Actions\Action::make('import')
                    ->label(__('sanabel.actions.import'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label(__('sanabel.reference.import_file'))
                            ->helperText(__('sanabel.rent_reference.import_help'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        try {
                            $result = app(ReferenceImporter::class)
                                ->importRentReferences($data['file']->getRealPath(), auth()->id());
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
