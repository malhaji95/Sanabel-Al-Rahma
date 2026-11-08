<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Models\Member;
use App\Models\Subscription;
use App\Services\MediaService;
use App\Services\MembershipService;
use App\Services\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * T-269 — the member's subscriptions, which nothing could create and nothing
 * could settle.
 *
 * `generateSubscriptions()` and `markPaid()` were both written with T-32 and
 * neither was ever called: the member screen edited a name and a category and
 * stopped there. So a membership could be opened and never billed, and the
 * membership fund was a fund nothing ever reached.
 *
 * Subscription money is not a donation and never funds a family (rule in
 * docs/03-rules.md §13). It is recorded against its own fund, and the proof
 * of payment goes to the private disk like every other paper.
 */
class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.member.subscriptions');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('period')
                    ->label(__('sanabel.member.period'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('sanabel.member.due_date'))
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.member.subscription_amount'))
                    ->numeric()
                    ->suffix(' '.Money::short()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.member.subscription_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.subscription_status.'.$state))
                    ->color(fn (string $state) => $state === 'paid' ? 'success' : 'warning'),

                Tables\Columns\IconColumn::make('payment_media_id')
                    ->label(__('sanabel.member.proof'))
                    ->boolean(),
            ])
            ->defaultSort('due_date')
            ->paginated([12, 24, 48])
            ->headerActions([
                Tables\Actions\Action::make('generate')
                    ->label(__('sanabel.member.generate'))
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn () => auth()->user()?->can_('manage_members') ?? false)
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('sanabel.member.from'))
                            ->default(fn () => $this->getOwnerRecord()->joined_at)
                            ->required(),

                        Forms\Components\DatePicker::make('to')
                            ->label(__('sanabel.member.to'))
                            ->default(fn () => now()->endOfYear())
                            ->required()
                            ->after('from'),

                        Forms\Components\TextInput::make('amount')
                            ->label(__('sanabel.member.subscription_amount'))
                            ->helperText(__('sanabel.member.amount_help'))
                            ->numeric()
                            ->minValue(1)
                            ->suffix(Money::short())
                            ->required(),

                        Forms\Components\Select::make('cycle')
                            ->label(__('sanabel.member.cycle'))
                            ->options(__('sanabel.member.cycles'))
                            ->default('monthly')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        /** @var Member $member */
                        $member = $this->getOwnerRecord();

                        // firstOrCreate inside the service, so running it twice
                        // over the same months adds nothing and loses nothing.
                        $count = app(MembershipService::class)->generateSubscriptions(
                            $member,
                            Carbon::parse($data['from']),
                            Carbon::parse($data['to']),
                            (int) $data['amount'],
                            $data['cycle'],
                        );

                        Notification::make()
                            ->title(__('sanabel.member.generated', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('markPaid')
                    ->label(__('sanabel.member.mark_paid'))
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (Subscription $record) => $record->status !== 'paid'
                        && (auth()->user()?->can_('manage_members') ?? false))
                    ->form([
                        // Held on the local disk only for the moment it
                        // takes the service to move it to the private one,
                        // exactly as a family's document is.
                        Forms\Components\FileUpload::make('proof')
                            ->label(__('sanabel.member.proof'))
                            ->helperText(__('sanabel.member.proof_help'))
                            ->required()
                            ->storeFiles(false)
                            ->maxSize(8192),
                    ])
                    ->action(function (Subscription $record, array $data) {
                        try {
                            // The private disk, like every other paper: a
                            // membership receipt is nobody's to browse.
                            $media = app(MediaService::class)
                                ->store($data['proof'], $record, 'membership_payment', auth()->user());

                            app(MembershipService::class)->markPaid($record, $media->getKey());
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('sanabel.member.marked_paid'))->success()->send();
                    }),
            ]);
    }
}
