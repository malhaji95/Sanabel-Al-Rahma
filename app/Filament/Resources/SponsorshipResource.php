<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipResource\Pages;
use App\Models\Sponsorship;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** T-25 — installments are generated from the range; an unpaid one is never coverage. */
class SponsorshipResource extends Resource
{
    protected static ?string $model = Sponsorship::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.sponsorship.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.sponsorship.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('donor_id')
                ->label(__('sanabel.donation.donor'))
                ->relationship('donor', 'name_ar')->searchable()->required(),

            Forms\Components\Select::make('beneficiary_id')
                ->label(__('sanabel.referral.case'))
                ->relationship('beneficiary', 'file_number')->searchable()->required(),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.sponsorship.amount'))
                ->numeric()->minValue(1)->required()
                ->suffix(config('sanabel.currency')),

            // Both dates are required — the installments are generated from them.
            Forms\Components\DatePicker::make('start_date')->label(__('sanabel.sponsorship.start'))->required(),
            Forms\Components\DatePicker::make('end_date')
                ->label(__('sanabel.sponsorship.end'))
                ->required()
                ->after('start_date'),

            Forms\Components\Select::make('status')
                ->label(__('sanabel.beneficiary.status'))
                ->options(__('sanabel.sponsorship_status'))
                ->default('active')->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('donor.name_ar')->label(__('sanabel.donation.donor'))->searchable(),
                Tables\Columns\TextColumn::make('beneficiary.file_number')->label(__('sanabel.referral.case'))->searchable(),
                Tables\Columns\TextColumn::make('amount')->label(__('sanabel.sponsorship.amount'))->numeric(),
                Tables\Columns\TextColumn::make('start_date')->label(__('sanabel.sponsorship.start'))->date(),
                Tables\Columns\TextColumn::make('end_date')->label(__('sanabel.sponsorship.end'))->date(),
                Tables\Columns\TextColumn::make('installments_count')
                    ->counts('installments')
                    ->label(__('sanabel.sponsorship.installments')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'lapsed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.sponsorship_status')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSponsorships::route('/'),
            'create' => Pages\CreateSponsorship::route('/create'),
            'edit' => Pages\EditSponsorship::route('/{record}/edit'),
        ];
    }
}
