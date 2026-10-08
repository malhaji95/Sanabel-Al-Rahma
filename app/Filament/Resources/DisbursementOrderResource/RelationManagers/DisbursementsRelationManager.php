<?php

namespace App\Filament\Resources\DisbursementOrderResource\RelationManagers;

use App\Filament\Resources\DisbursementOrderResource;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The lines inside one order. Each keeps its own state, so a single failure
 * does not hold the rest of the order up, and each step is visible only to
 * the account that holds it.
 */
class DisbursementsRelationManager extends RelationManager
{
    protected static string $relationship = 'disbursements';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.disbursement.lines');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.disbursement.amount'))->numeric(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.disbursement.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'reconciled', 'executed' => 'success',
                        'approved' => 'info',
                        'rejected', 'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('transfer_ref')
                    ->label(__('sanabel.disbursement.transfer_ref'))->placeholder('—'),
                Tables\Columns\TextColumn::make('reject_reason_ar')
                    ->label(__('sanabel.disbursement.reject_reason'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('failure_reason_ar')
                    ->label(__('sanabel.disbursement.failure_reason'))->placeholder('—')->toggleable(),
            ])
            ->actions([
                // Set one line aside while the order goes through.
                Tables\Actions\Action::make('reject')
                    ->label(__('sanabel.disbursement.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Disbursement $record) => in_array($record->status, ['in_order', 'approved'], true)
                        && auth()->user()?->can_('approve_disbursement'))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('sanabel.disbursement.reject_reason'))->required()->rows(2),
                    ])
                    ->action(fn (Disbursement $record, array $data) => DisbursementOrderResource::run(
                        fn () => app(DisbursementService::class)
                            ->reject($record, auth()->user(), $data['reason']),
                        __('sanabel.disbursement.rejected'),
                    )),

                Tables\Actions\Action::make('execute')
                    ->label(__('sanabel.disbursement.execute'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->visible(fn (Disbursement $record) => $record->status === 'approved'
                        && auth()->user()?->can_('execute_disbursement'))
                    ->form([
                        Forms\Components\TextInput::make('transfer_ref')
                            ->label(__('sanabel.disbursement.transfer_ref'))->required(),
                    ])
                    ->action(fn (Disbursement $record, array $data) => DisbursementOrderResource::run(
                        fn () => app(DisbursementService::class)
                            ->execute($record, auth()->user(), $data['transfer_ref']),
                        __('sanabel.disbursement.executed'),
                    )),

                Tables\Actions\Action::make('fail')
                    ->label(__('sanabel.disbursement.fail'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (Disbursement $record) => $record->status === 'approved'
                        && auth()->user()?->can_('execute_disbursement'))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('sanabel.disbursement.failure_reason'))->required()->rows(2),
                    ])
                    ->action(fn (Disbursement $record, array $data) => DisbursementOrderResource::run(
                        fn () => app(DisbursementService::class)->fail($record, $data['reason']),
                        __('sanabel.disbursement.failed'),
                    )),

                Tables\Actions\Action::make('reconcile')
                    ->label(__('sanabel.disbursement.reconcile'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (Disbursement $record) => $record->status === 'executed'
                        && auth()->user()?->can_('reconcile_disbursement'))
                    ->requiresConfirmation()
                    ->action(fn (Disbursement $record) => DisbursementOrderResource::run(
                        fn () => app(DisbursementService::class)->reconcile($record, auth()->user()),
                        __('sanabel.disbursement.reconciled'),
                    )),
            ])
            ->paginated([25, 50, 100]);
    }
}
