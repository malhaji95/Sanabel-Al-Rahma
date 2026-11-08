<?php

namespace App\Filament\Resources\SponsorshipResource\RelationManagers;

use App\Models\Donation;
use App\Models\Sponsorship;
use App\Models\SponsorshipInstallment;
use App\Services\Money;
use App\Services\SponsorshipService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * T-266 — the months of a sponsorship, and the one thing that could never
 * be said about them.
 *
 * The installments have been generated since T-25 and the screen showed
 * their number and nothing else. `markPaid()` was written then too, and
 * nothing in the application ever called it — so a month fell due, fell
 * overdue, was counted overdue in the reports, and after two of them the
 * lapse rule stopped the sponsorship. There was no way to say a sponsor had
 * paid, so left alone every sponsorship lapsed.
 *
 * Read-only otherwise: the rows are generated from the sponsorship's own
 * range and are not a thing to add to or delete by hand.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.sponsorship.installments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label(__('sanabel.sponsorship.period'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('sanabel.sponsorship.due_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.sponsorship.amount'))
                    ->numeric()
                    ->suffix(' '.Money::short()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.sponsorship.installment_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.installment_status.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        default => 'warning',
                    }),

                // The transfer that paid it, so the figure can be traced back
                // to the money rather than taken on trust.
                Tables\Columns\TextColumn::make('donation.reference_no')
                    ->label(__('sanabel.sponsorship.donation'))
                    ->placeholder(__('sanabel.sponsorship.no_donation')),
            ])
            ->defaultSort('due_date')
            ->paginated([12, 24, 48])
            ->actions([
                Tables\Actions\Action::make('markPaid')
                    ->label(__('sanabel.sponsorship.mark_paid'))
                    ->modalHeading(__('sanabel.sponsorship.mark_paid_heading'))
                    ->icon('heroicon-o-banknotes')
                    // Only on a month that is still owed, and only for an
                    // account that may touch money.
                    ->visible(fn (SponsorshipInstallment $record) => $record->status !== 'paid'
                        && (auth()->user()?->can_('verify_payment') ?? false))
                    ->form([
                        Forms\Components\Select::make('donation_id')
                            ->label(__('sanabel.sponsorship.donation'))
                            ->helperText(__('sanabel.sponsorship.mark_paid_help'))
                            ->options(fn () => $this->eligibleDonations())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (SponsorshipInstallment $record, array $data) {
                        try {
                            app(SponsorshipService::class)->markPaid($record, (int) $data['donation_id']);
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('sanabel.sponsorship.marked_paid'))->success()->send();
                    }),
            ]);
    }

    /**
     * The sponsor's own verified transfers.
     *
     * A transfer already recorded against another month stays on the list on
     * purpose: a sponsor who pays a year at once settles twelve months with
     * one of them, and hiding it would make that impossible to record.
     *
     * @return array<int,string>
     */
    private function eligibleDonations(): array
    {
        /** @var Sponsorship $sponsorship */
        $sponsorship = $this->getOwnerRecord();

        return Donation::query()
            ->where('donor_id', $sponsorship->donor_id)
            ->where('status', 'verified')
            ->whereNull('reversal_of_id')
            ->orderByDesc('verified_at')
            ->get()
            ->mapWithKeys(fn (Donation $d) => [
                $d->id => $d->reference_no.' — '.number_format($d->amount).' '.Money::short(),
            ])
            ->all();
    }
}
