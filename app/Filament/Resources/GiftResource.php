<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GiftResource\Pages;
use App\Models\Gift;
use App\Services\GiftService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-241 — the cards given, read and never edited.
 *
 * What a donor wrote on a card is what the card says, and the card has
 * already gone out; a mistake is a conversation with the donor rather than
 * a quiet correction behind them. So the screen offers one action: the
 * card's own address, for the desk that is asked «where is my card».
 *
 * Rule 10 — the recipient's number is not a column here. It is encrypted on
 * the row and it is the donor's to use, through the share link.
 */
class GiftResource extends Resource
{
    protected static ?string $model = Gift::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.gift.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.gift.cards');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('donation.transaction_ref')
                    ->label(__('sanabel.donation.transaction_ref'))->searchable(),
                Tables\Columns\TextColumn::make('giver_name_ar')
                    ->label(__('sanabel.gift.giver'))->searchable(),
                Tables\Columns\TextColumn::make('recipient_name_ar')
                    ->label(__('sanabel.gift.recipient'))->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.gift.amount'))
                    ->state(fn (Gift $record) => number_format($record->amount)),
                Tables\Columns\TextColumn::make('campaign.title_ar')
                    ->label(__('sanabel.gift.project'))
                    ->placeholder(__('sanabel.gift.general')),
                Tables\Columns\IconColumn::make('show_amount')
                    ->label(__('sanabel.gift.show_amount'))->boolean(),
                Tables\Columns\TextColumn::make('send_at')
                    ->label(__('sanabel.gift.send_at'))->dateTime('Y-m-d H:i')
                    ->placeholder(__('sanabel.gift.send_now')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.gift.status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.gift.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state) => $state === 'issued' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label(__('sanabel.gift.issued_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.gift.status'))
                    ->options(__('sanabel.gift.statuses')),
            ])
            ->actions([
                Tables\Actions\Action::make('openCard')
                    ->label(__('sanabel.gift.token'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (Gift $record) => $record->status === 'issued')
                    ->url(fn (Gift $record) => app(GiftService::class)->cardUrl($record))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGifts::route('/')];
    }
}
