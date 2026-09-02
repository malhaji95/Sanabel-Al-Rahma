<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DonationResource\Pages;
use App\Models\Donation;
use App\Models\Fund;
use App\Payments\PaymentGateway;
use App\Services\DonationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-20 and T-21 — the verification queue.
 * A verified donation is never edited here; a correction creates a reversal.
 */
class DonationResource extends Resource
{
    protected static ?string $model = Donation::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.donation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.donation.plural');
    }

    /** The badge is the queue depth — the number a verifier actually acts on. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('donor_id')
                ->relationship('donor', 'name_ar')
                ->label(__('sanabel.donation.donor'))
                ->searchable()->required(),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.donation.amount'))
                ->numeric()->minValue(1)->required()
                ->suffix(config('sanabel.currency')),

            Forms\Components\TextInput::make('transaction_ref')
                ->label(__('sanabel.donation.transaction_ref'))
                ->required()
                ->unique(ignoreRecord: true)
                ->validationMessages(['unique' => __('sanabel.donations.duplicate_ref')]),

            Forms\Components\Select::make('fund_id')
                ->label(__('sanabel.donation.fund'))
                ->options(fn () => Fund::pluck('name_ar', 'id'))
                ->default(fn () => Fund::byKey(Fund::OPERATIONAL)->id)
                ->required(),

            Forms\Components\Select::make('route')
                ->label(__('sanabel.donation.route'))
                ->options(__('sanabel.donation_route'))
                ->default('platform')->required(),

            Forms\Components\TextInput::make('receipt_media_id')
                ->label(__('sanabel.donation.receipt'))
                ->numeric(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transaction_ref')->label(__('sanabel.donation.transaction_ref'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('donor.name_ar')->label(__('sanabel.donation.donor'))->searchable(),
                Tables\Columns\TextColumn::make('amount')->label(__('sanabel.donation.amount'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('fund.name_ar')->label(__('sanabel.donation.fund')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.donations.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'verified' => 'success',
                        'rejected' => 'danger',
                        'reversed' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label(__('sanabel.donation.received_at'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('verified_at')->label(__('sanabel.donation.verified_at'))->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options([
                        'pending' => __('sanabel.donations.pending'),
                        'verified' => __('sanabel.donations.verified'),
                        'rejected' => __('sanabel.donations.rejected'),
                        'reversed' => __('sanabel.donations.reversed'),
                    ])
                    ->default('pending'),
                Tables\Filters\SelectFilter::make('fund_id')
                    ->label(__('sanabel.donation.fund'))
                    ->options(fn () => Fund::pluck('name_ar', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label(__('sanabel.actions.verify'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Donation $record) => auth()->user()->can('verify', $record))
                    ->requiresConfirmation()
                    ->action(function (Donation $record) {
                        app(PaymentGateway::class)->verify($record, auth()->id());

                        Notification::make()->title(__('sanabel.actions.verified'))->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label(__('sanabel.actions.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Donation $record) => auth()->user()->can('reject', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')->label(__('sanabel.actions.reason'))->required(),
                    ])
                    ->action(function (Donation $record, array $data) {
                        app(PaymentGateway::class)->reject($record, auth()->id(), $data['reason']);

                        Notification::make()->title(__('sanabel.donations.rejected'))->danger()->send();
                    }),

                // Rule 5 — the only way to correct verified money.
                Tables\Actions\Action::make('reverse')
                    ->label(__('sanabel.actions.reverse'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Donation $record) => auth()->user()->can('reverse', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')->label(__('sanabel.actions.reason'))->required(),
                    ])
                    ->action(function (Donation $record, array $data) {
                        app(DonationService::class)->reverse($record, auth()->id(), $data['reason']);

                        Notification::make()->title(__('sanabel.actions.reversed'))->warning()->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Donation $record) => auth()->user()->can('update', $record)),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDonations::route('/'),
            'create' => Pages\CreateDonation::route('/create'),
            'edit' => Pages\EditDonation::route('/{record}/edit'),
        ];
    }
}
