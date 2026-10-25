<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DonationResource\Pages;
use App\Models\Donation;
use App\Models\Fund;
use App\Payments\PaymentGateway;
use App\Services\DonationService;
use App\Services\MediaService;
use App\Services\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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

            /*
             | T-142 — «وموضوع تعديل المبالغ ليش في تعديل؟», 28 October.
             |
             | Because a transfer is read off a bank statement by a person,
             | and a person mistypes. The window is the one rule 5 leaves
             | open: before verification, a pending row may be corrected.
             | After it, nothing on this screen opens — the policy refuses the
             | edit — and a correction is a reversal with its own linked row.
             | The screen says so where the figure is typed.
             */
            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.donation.amount'))
                ->numeric()->minValue(1)->required()
                ->helperText(__('sanabel.donation.amount_edit_help'))
                ->suffix(Money::short()),

            Forms\Components\TextInput::make('transaction_ref')
                ->label(__('sanabel.donation.transaction_ref'))
                ->required()
                ->unique(ignoreRecord: true)
                ->validationMessages(['unique' => __('sanabel.donations.duplicate_ref')]),

            Forms\Components\Select::make('fund_id')
                ->label(__('sanabel.donation.fund'))
                ->helperText(__('sanabel.donation.fund_help'))
                ->options(fn () => Fund::pluck('name_ar', 'id'))
                ->default(fn () => Fund::byKey(Fund::OPERATIONAL)->id)
                ->required(),

            /*
             | T-142 — «وما المقصود بتبرع مباشر او عبر المنصة؟», 28 October.
             | Where the association learned of the transfer, and nothing
             | about who gave it or what it is for: «عبر المنصة» is a row the
             | donor created on the site themselves, «مباشر» is money that
             | reached the association outside it — a counter, a wallet, a
             | bank statement — and was entered here by its staff.
             */
            Forms\Components\Select::make('route')
                ->label(__('sanabel.donation.route'))
                ->helperText(__('sanabel.donation.route_help'))
                ->options(__('sanabel.donation_route'))
                ->default('platform')->required(),

            /*
             | Derived, never typed: money that arrives with a basket behind it
             | is earmarked for the files in that basket, and money entered by
             | hand is general until the association allocates it from
             | «توزيع المال العام». Shown because the treasurer asked what
             | decides it.
             */
            Forms\Components\Placeholder::make('designation_display')
                ->label(__('sanabel.designation.label'))
                ->helperText(__('sanabel.donation.designation_help'))
                ->content(fn (?Donation $record) => __('sanabel.designation.'.($record?->designation ?? 'general'))),

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
                Tables\Columns\TextColumn::make('designation')
                    ->label(__('sanabel.designation.label'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.designation.'.$state))
                    ->badge()
                    ->color(fn (string $state) => $state === 'general' ? 'warning' : 'success'),
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
                Tables\Filters\SelectFilter::make('designation')
                    ->label(__('sanabel.designation.label'))
                    ->options(fn () => [
                        'earmarked' => __('sanabel.designation.earmarked'),
                        'general' => __('sanabel.designation.general'),
                    ]),
                Tables\Filters\SelectFilter::make('fund_id')
                    ->label(__('sanabel.donation.fund'))
                    ->options(fn () => Fund::pluck('name_ar', 'id')),
            ])
            ->actions([
                // The other half of the trail: the donor uploaded a deposit
                // slip, and this is the record of the transfer the association
                // made onward. It is shown to the donor, so the caption warns
                // against a screenshot carrying a recipient's name or wallet —
                // rule 2 stands, image or not.
                Tables\Actions\Action::make('transferRecord')
                    ->label(__('sanabel.donation.attach_transfer_record'))
                    ->icon('heroicon-o-paper-clip')
                    ->color('gray')
                    ->visible(fn (Donation $record) => $record->status === 'verified'
                        && auth()->user()->can_('verify_payment'))
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label(__('sanabel.donation.transfer_record'))
                            ->helperText(__('sanabel.donation.transfer_record_help'))
                            ->image()
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(function (Donation $record, array $data) {
                        try {
                            $media = app(MediaService::class)
                                ->store($data['file'], $record, 'transfer_record', auth()->user());
                        } catch (\RuntimeException $e) {
                            // T-58 — the type and size gate speaks Arabic.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        $record->forceFill(['transfer_record_media_id' => $media->getKey()])->save();

                        Notification::make()->title(__('sanabel.donation.transfer_record_saved'))->success()->send();
                    }),

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
            ->bulkActions([
                /*
                 | T-142 — «ممكن عنده كشف حساب وعم يراجعهم: حوالة, حوالة,
                 | وبعدها بيعطي تأكيد … يعني ممكن يأكد 20 حبة او اكثر»,
                 | 28 October.
                 |
                 | One at a time with a confirmation each is the right shape
                 | for a transfer somebody is deciding about; it is the wrong
                 | shape for a statement of twenty that have already been
                 | matched line by line. So the same verification, over a
                 | selection — and it is the same verification: every row goes
                 | through the gateway and the policy exactly as the single
                 | button does, so nothing is confirmed here that could not be
                 | confirmed there. What the selection holds and the account
                 | may not verify is left alone and counted out loud, rather
                 | than skipped in silence.
                 */
                Tables\Actions\BulkAction::make('verifySelected')
                    ->label(__('sanabel.donation.verify_selected'))
                    ->modalDescription(__('sanabel.donation.verify_selected_help'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->can_('verify_payment'))
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $verified = 0;
                        $skipped = 0;

                        foreach ($records as $donation) {
                            if (! auth()->user()->can('verify', $donation)) {
                                $skipped++;

                                continue;
                            }

                            app(PaymentGateway::class)->verify($donation, auth()->id());
                            $verified++;
                        }

                        if ($verified === 0) {
                            Notification::make()
                                ->title(__('sanabel.donation.nothing_to_verify'))
                                ->warning()->send();

                            return;
                        }

                        Notification::make()
                            ->title($skipped > 0
                                ? __('sanabel.donation.verified_count_skipped', ['count' => $verified, 'skipped' => $skipped])
                                : __('sanabel.donation.verified_count', ['count' => $verified]))
                            ->success()->send();
                    }),
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
