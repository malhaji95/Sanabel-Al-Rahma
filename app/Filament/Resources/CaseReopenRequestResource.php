<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CaseReopenRequestResource\Pages;
use App\Models\CaseReopenRequest;
use App\Models\Scopes\RegionScope;
use App\Services\CaseService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * T-239 — «سجلًا يوضح من طلب إعادة الفتح ومن وافق عليه», 3 November.
 *
 * The audit log already holds it. A log is read by whoever knows to look,
 * so this is the same record on a screen, opening on what is still waiting.
 */
class CaseReopenRequestResource extends Resource
{
    protected static ?string $model = CaseReopenRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-open';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.reopen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.reopen.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /** T-209 — a scoped account reads only requests about its own files. */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! in_array($user?->role?->key, RegionScope::SCOPED_ROLES, true) || ! $user->region_id) {
            return parent::getEloquentQuery();
        }

        return parent::getEloquentQuery()->whereHas('beneficiary');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('beneficiary.file_number')
                ->label(__('sanabel.beneficiary.file_number'))->disabled(),
            Forms\Components\TextInput::make('from_status')
                ->label(__('sanabel.reopen.from_status'))
                ->formatStateUsing(fn (?string $state) => $state ? __('sanabel.status.'.$state) : null)
                ->disabled(),
            Forms\Components\Textarea::make('reason_ar')
                ->label(__('sanabel.reopen.reason'))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('note_ar')
                ->label(__('sanabel.reopen.note'))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('decision_note_ar')
                ->label(__('sanabel.reopen.decision_note'))->disabled()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('from_status')
                    ->label(__('sanabel.reopen.from_status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.status.'.$state))
                    ->badge(),
                Tables\Columns\TextColumn::make('reason_ar')
                    ->label(__('sanabel.reopen.reason'))->wrap(),
                Tables\Columns\TextColumn::make('requester.name')
                    ->label(__('sanabel.reopen.requested_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.reopen.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'refused' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('decider.name')
                    ->label(__('sanabel.reopen.decided_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('to_status')
                    ->label(__('sanabel.reopen.to_status'))
                    ->formatStateUsing(fn (?string $state) => $state ? __('sanabel.status.'.$state) : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('sanabel.change_request.requested_at'))->dateTime('Y-m-d H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.reopen.statuses'))
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.actions.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (CaseReopenRequest $record) => $record->status === 'pending'
                        && auth()->user()?->can_('approve_case'))
                    ->requiresConfirmation()
                    ->action(fn (CaseReopenRequest $record) => static::run(
                        fn () => app(CaseService::class)->approveReopen($record, auth()->user()),
                        __('sanabel.reopen.approved'),
                    )),

                Tables\Actions\Action::make('refuse')
                    ->label(__('sanabel.actions.reject'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (CaseReopenRequest $record) => $record->status === 'pending'
                        && auth()->user()?->can_('approve_case'))
                    ->form([
                        Forms\Components\Textarea::make('note_ar')
                            ->label(__('sanabel.reopen.decision_note'))->rows(3)->required(),
                    ])
                    ->action(fn (CaseReopenRequest $record, array $data) => static::run(
                        fn () => app(CaseService::class)->refuseReopen(
                            $record, auth()->user(), $data['note_ar'] ?? '',
                        ),
                        __('sanabel.reopen.refused'),
                    )),

                Tables\Actions\ViewAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCaseReopenRequests::route('/'),
            'view' => Pages\ViewCaseReopenRequest::route('/{record}'),
        ];
    }
}
