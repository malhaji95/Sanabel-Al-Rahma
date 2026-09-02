<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralResource\Pages;
use App\Models\Referral;
use App\Models\Setting;
use App\Services\ReferralService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** T-29 — single-use referral cards with an expiry and a revoke. */
class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.referral.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.referral.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('beneficiary_id')
                ->label(__('sanabel.referral.case'))
                ->relationship('beneficiary', 'file_number')->searchable()->required(),

            Forms\Components\Select::make('provider_id')
                ->label(__('sanabel.referral.provider'))
                ->relationship('provider', 'name_ar')->searchable()->required(),

            Forms\Components\DateTimePicker::make('expires_at')
                ->label(__('sanabel.referral.expires_at'))
                ->default(fn () => now()->addDays((int) Setting::value(
                    'referral_validity_days',
                    config('sanabel.setting_defaults.referral_validity_days')
                )))
                ->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('sanabel.referral.code'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('beneficiary.file_number')->label(__('sanabel.referral.case'))->searchable(),
                Tables\Columns\TextColumn::make('provider.name_ar')->label(__('sanabel.referral.provider')),
                Tables\Columns\TextColumn::make('expires_at')->label(__('sanabel.referral.expires_at'))->dateTime(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'issued' => 'success',
                        'used' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.referral_status')),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label(__('sanabel.actions.revoke'))
                    ->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Referral $record) => $record->status === 'issued')
                    ->requiresConfirmation()
                    ->action(function (Referral $record) {
                        app(ReferralService::class)->revoke($record);

                        Notification::make()->title(__('sanabel.actions.revoked'))->danger()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferrals::route('/'),
            'create' => Pages\CreateReferral::route('/create'),
        ];
    }
}
