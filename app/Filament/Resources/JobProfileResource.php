<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobProfileResource\Pages;
use App\Models\JobProfile;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Published only for an approved case, and never with a phone or address. */
class JobProfileResource extends Resource
{
    protected static ?string $model = JobProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.job_profile.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.job_profile.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('beneficiary_id')->label(__('sanabel.job_profile.case'))->relationship('beneficiary', 'file_number')->searchable()->required(),
            Forms\Components\TextInput::make('trade_key')->label(__('sanabel.job_profile.trade'))->required(),
            Forms\Components\Textarea::make('summary_ar')->label(__('sanabel.job_profile.summary'))->required()->columnSpanFull(),
            Forms\Components\Select::make('region_id')->label(__('sanabel.beneficiary.region'))->relationship('region', 'name_ar')->searchable()->required(),
            Forms\Components\TextInput::make('availability')->label(__('sanabel.job_profile.availability')),
            Forms\Components\Select::make('status')->label(__('sanabel.beneficiary.status'))->options(__('sanabel.job_profile_status'))->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')->label(__('sanabel.beneficiary.file_number'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('trade_key')->label(__('sanabel.job_profile.trade'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
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
            'index' => Pages\ListJobProfiles::route('/'),
            'create' => Pages\CreateJobProfile::route('/create'),
            'edit' => Pages\EditJobProfile::route('/{record}/edit'),
        ];
    }
}
