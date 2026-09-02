<?php

namespace App\Filament\Resources\DistributionResource\RelationManagers;

use App\Models\DistributionItem;
use App\Services\DistributionService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Transfers are executed manually and confirmed one by one. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.distribution.items');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('amount')->label(__('sanabel.distribution.per_family'))->numeric(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'executed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('failure_reason_ar')->label(__('sanabel.distribution.failure_reason'))->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('execute')
                    ->label(__('sanabel.actions.execute'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (DistributionItem $record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('proof_media_id')
                            ->label(__('sanabel.distribution.proof'))
                            ->numeric()->required(),
                    ])
                    ->action(function (DistributionItem $record, array $data) {
                        app(DistributionService::class)->execute($record, (int) $data['proof_media_id']);

                        Notification::make()->title(__('sanabel.actions.executed'))->success()->send();
                    }),

                Tables\Actions\Action::make('fail')
                    ->label(__('sanabel.actions.mark_failed'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (DistributionItem $record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('reason_ar')->label(__('sanabel.actions.reason'))->required(),
                    ])
                    ->action(function (DistributionItem $record, array $data) {
                        app(DistributionService::class)->fail($record, $data['reason_ar']);

                        Notification::make()->title(__('sanabel.actions.marked_failed'))->danger()->send();
                    }),
            ]);
    }
}
