<?php

namespace App\Filament\Resources\DisbursementOrderResource\RelationManagers;

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\DisbursementOrderResource;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use App\Services\Money;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The lines inside one order. Each keeps its own state, so a single failure
 * does not hold the rest of the order up, and each step is visible only to
 * the account that holds it.
 */
class DisbursementsRelationManager extends RelationManager
{
    protected static string $relationship = 'disbursements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.disbursement.lines');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 | T-141 — the approver reads the family from the screen they
                 | approve on, not from another list they have to find. The
                 | read-only screen, because that is the one an approver can
                 | open; a plain string for an account that may not read it.
                 */
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable()
                    ->url(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary)
                            ? BeneficiaryResource::getUrl('view', ['record' => $record->beneficiary_id])
                            : null)
                    ->openUrlInNewTab()
                    ->color(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary) ? 'primary' : null)
                    ->tooltip(fn (Disbursement $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary)
                            ? __('sanabel.disbursement.open_case')
                            : null),

                Tables\Columns\TextColumn::make('beneficiary.region.name_ar')
                    ->label(__('sanabel.beneficiary.region'))
                    ->toggleable(),

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
            ->bulkActions([
                /*
                 | T-216 — «زر التحويل الجماعي يحفظ نتيجة كل حوالة لحالها,
                 | حتى لو نجح بعضها وفشل بعضها», 1 November.
                 |
                 | An order of forty families was forty modals, each asking
                 | for one reference. This asks for all of them at once and
                 | is still forty transfers: every row carries its own
                 | reference and is saved on its own, so the twelfth failing
                 | leaves the eleven before it paid and the rest to be tried.
                 |
                 | A row left blank is not an error and not a failure — it is
                 | a line the treasurer has not moved yet, and it stays
                 | approved, waiting. A row marked as failed takes a reason,
                 | which is the same thing the single button asks for.
                 |
                 | Nothing here reaches past the service: each row goes
                 | through `execute()` or `fail()` exactly as one button
                 | would, with the same guard against the hand that approved
                 | it also moving the money.
                 */
                Tables\Actions\BulkAction::make('executeMany')
                    ->label(__('sanabel.disbursement.execute_many'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->modalDescription(__('sanabel.disbursement.execute_many_help'))
                    ->visible(fn () => auth()->user()?->can_('execute_disbursement'))
                    ->form(fn (Collection $records): array => $records
                        ->where('status', 'approved')
                        ->map(fn (Disbursement $line) => Forms\Components\Fieldset::make('line-'.$line->getKey())
                            ->label($line->beneficiary?->file_number.' — '.number_format($line->amount).' '.Money::short($line->currency))
                            ->columns(3)
                            ->schema([
                                Forms\Components\TextInput::make('ref_'.$line->getKey())
                                    ->label(__('sanabel.disbursement.transfer_ref'))
                                    ->helperText(__('sanabel.disbursement.leave_blank_to_skip'))
                                    ->requiredIf('outcome_'.$line->getKey(), 'executed'),

                                Forms\Components\Select::make('outcome_'.$line->getKey())
                                    ->label(__('sanabel.disbursement.outcome'))
                                    ->options([
                                        'skip' => __('sanabel.disbursement.outcome_skip'),
                                        'executed' => __('sanabel.disbursement.outcome_executed'),
                                        'failed' => __('sanabel.disbursement.outcome_failed'),
                                    ])
                                    ->default('skip')
                                    ->live(),

                                Forms\Components\TextInput::make('why_'.$line->getKey())
                                    ->label(__('sanabel.disbursement.failure_reason'))
                                    ->requiredIf('outcome_'.$line->getKey(), 'failed')
                                    ->visible(fn (Forms\Get $get) => $get('outcome_'.$line->getKey()) === 'failed'),
                            ]))
                        ->values()
                        ->all())
                    ->action(function (Collection $records, array $data) {
                        $service = app(DisbursementService::class);
                        $done = 0;
                        $failed = 0;
                        $refused = [];

                        foreach ($records->where('status', 'approved') as $line) {
                            $outcome = $data['outcome_'.$line->getKey()] ?? 'skip';

                            if ($outcome === 'skip') {
                                continue;
                            }

                            /*
                             | Each row is saved on its own and its refusal is
                             | collected rather than thrown: one line the
                             | treasurer may not move — because they approved
                             | it themselves — must not undo the ones already
                             | written above it.
                             */
                            try {
                                $outcome === 'failed'
                                    ? $service->fail($line, (string) $data['why_'.$line->getKey()])
                                    : $service->execute(
                                        $line,
                                        auth()->user(),
                                        (string) $data['ref_'.$line->getKey()],
                                    );

                                $outcome === 'failed' ? $failed++ : $done++;
                            } catch (\RuntimeException $e) {
                                $refused[] = $line->beneficiary?->file_number.': '.$e->getMessage();
                            }
                        }

                        Notification::make()
                            ->title(__('sanabel.disbursement.execute_many_done', [
                                'done' => $done,
                                'failed' => $failed,
                                'refused' => count($refused),
                            ]))
                            ->body($refused === [] ? null : implode("\n", $refused))
                            ->color($refused === [] ? 'success' : 'warning')
                            ->persistent()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->paginated([25, 50, 100]);
    }
}
