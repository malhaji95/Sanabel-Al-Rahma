<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DisbursementOrderResource\Pages;
use App\Filament\Resources\DisbursementOrderResource\RelationManagers\DisbursementsRelationManager;
use App\Models\DisbursementOrder;
use App\Services\DisbursementService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The order itself: raised by the treasurer, approved by the executive
 * director or the deputy, and frozen from that moment on.
 */
class DisbursementOrderResource extends Resource
{
    protected static ?string $model = DisbursementOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.disbursement.order_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.disbursement.order_plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('reference_no')
                ->label(__('sanabel.disbursement.reference_no'))->disabled(),
            Forms\Components\Textarea::make('notes_ar')
                ->label(__('sanabel.disbursement.notes'))
                ->rows(2)
                ->columnSpanFull()
                ->disabled(fn (?DisbursementOrder $record) => $record?->isFrozen() ?? false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference_no')
                    ->label(__('sanabel.disbursement.reference_no'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('disbursements_count')
                    ->label(__('sanabel.disbursement.lines'))->counts('disbursements'),
                Tables\Columns\TextColumn::make('total')
                    ->label(__('sanabel.disbursement.total'))
                    ->state(fn (DisbursementOrder $record) => number_format($record->total())),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.disbursement.order_statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'settled' => 'success',
                        'approved', 'executing' => 'info',
                        'pending_approval' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('approved_duly')
                    ->label(__('sanabel.disbursement.duly'))->boolean(),
                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label(__('sanabel.disbursement.approved_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label(__('sanabel.disbursement.approved_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('submit')
                    ->label(__('sanabel.disbursement.submit'))
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (DisbursementOrder $record) => $record->status === 'draft'
                        && auth()->user()?->can_('raise_disbursement_order'))
                    ->requiresConfirmation()
                    ->action(fn (DisbursementOrder $record) => static::run(
                        fn () => app(DisbursementService::class)->submit($record),
                        __('sanabel.disbursement.submitted'),
                    )),

                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.disbursement.approve'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (DisbursementOrder $record) => $record->status === 'pending_approval'
                        && auth()->user()?->can_('approve_disbursement'))
                    // The wording the association asked for, shown before the
                    // signature rather than after it.
                    ->modalDescription(__('sanabel.disbursement.approve_confirm'))
                    ->requiresConfirmation()
                    ->action(fn (DisbursementOrder $record) => static::run(
                        fn () => app(DisbursementService::class)->approve($record, auth()->user()),
                        __('sanabel.disbursement.approved_duly_done'),
                    )),

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /** Model rules throw; the panel shows the reason instead of a stack trace. */
    public static function run(callable $step, string $success): void
    {
        try {
            $step();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public static function getRelations(): array
    {
        return [DisbursementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisbursementOrders::route('/'),
            'edit' => Pages\EditDisbursementOrder::route('/{record}/edit'),
        ];
    }
}
