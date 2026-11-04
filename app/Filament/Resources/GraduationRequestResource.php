<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GraduationRequestResource\Pages;
use App\Models\GraduationRequest;
use App\Models\Scopes\RegionScope;
use App\Services\GraduationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * T-249 — the three hands on one screen, each offered only its own step.
 *
 * The supervisor sees a recommendation to give where the study is waiting
 * on one; the director sees a decision to take where the recommendation is
 * in. Neither is offered the other's step, and nobody is offered their own
 * twice: the service refuses it as well, because a screen is not a rule.
 */
class GraduationRequestResource extends Resource
{
    protected static ?string $model = GraduationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.graduation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.graduation.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::whereIn('status', ['pending_supervisor', 'pending_director'])->count();

        return $open > 0 ? (string) $open : null;
    }

    /** T-209 — a scoped account reads only studies about its own files. */
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
            Forms\Components\TextInput::make('monthly_income')
                ->label(__('sanabel.graduation.monthly_income'))->disabled(),
            Forms\Components\TextInput::make('need_snapshot')
                ->label(__('sanabel.graduation.need_snapshot'))->disabled(),
            Forms\Components\TextInput::make('stable_since')
                ->label(__('sanabel.graduation.stable_since'))->disabled(),
            Forms\Components\Textarea::make('study_note_ar')
                ->label(__('sanabel.graduation.study_note'))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('recommendation_note_ar')
                ->label(__('sanabel.graduation.recommendation_note'))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('decision_note_ar')
                ->label(__('sanabel.graduation.decision_note'))->disabled()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.beneficiary.file_number'))->searchable(),
                Tables\Columns\TextColumn::make('monthly_income')
                    ->label(__('sanabel.graduation.monthly_income'))
                    ->state(fn (GraduationRequest $record) => number_format($record->monthly_income)),
                Tables\Columns\TextColumn::make('need_snapshot')
                    ->label(__('sanabel.graduation.need_snapshot'))
                    ->state(fn (GraduationRequest $record) => number_format($record->need_snapshot)),
                Tables\Columns\TextColumn::make('stable_since')
                    ->label(__('sanabel.graduation.stable_since'))->date('Y-m-d'),
                Tables\Columns\TextColumn::make('requester.name')
                    ->label(__('sanabel.reopen.requested_by'))->placeholder('—'),
                Tables\Columns\TextColumn::make('recommendation')
                    ->label(__('sanabel.graduation.recommendation'))
                    ->formatStateUsing(fn (?string $state) => $state ? __('sanabel.graduation.recommendations.'.$state) : null)
                    ->badge()
                    ->color(fn (?string $state) => $state === 'object' ? 'danger' : 'success')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.graduation.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'refused' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('decider.name')
                    ->label(__('sanabel.reopen.decided_by'))->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.graduation.statuses')),
            ])
            ->actions([
                Tables\Actions\Action::make('recommend')
                    ->label(__('sanabel.graduation.recommendation'))
                    ->icon('heroicon-o-hand-thumb-up')
                    ->visible(fn (GraduationRequest $record) => $record->status === 'pending_supervisor'
                        && auth()->user()?->can_('recommend'))
                    ->form([
                        Forms\Components\Radio::make('recommendation')
                            ->label(__('sanabel.graduation.recommendation'))
                            ->options(__('sanabel.graduation.recommendations'))
                            ->required(),
                        Forms\Components\Textarea::make('note_ar')
                            ->label(__('sanabel.graduation.recommendation_note'))->rows(3)->required(),
                    ])
                    ->action(fn (GraduationRequest $record, array $data) => static::run(
                        fn () => app(GraduationService::class)->recommend(
                            $record, auth()->user(), $data['recommendation'] ?? '', $data['note_ar'] ?? '',
                        ),
                        __('sanabel.graduation.recommended'),
                    )),

                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.actions.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (GraduationRequest $record) => $record->status === 'pending_director'
                        && auth()->user()?->can_('suspend_graduate'))
                    ->modalDescription(__('sanabel.graduation.study_help'))
                    ->requiresConfirmation()
                    ->action(fn (GraduationRequest $record) => static::run(
                        fn () => app(GraduationService::class)->approve($record, auth()->user()),
                        __('sanabel.graduation.approved'),
                    )),

                Tables\Actions\Action::make('refuse')
                    ->label(__('sanabel.actions.reject'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (GraduationRequest $record) => $record->status === 'pending_director'
                        && auth()->user()?->can_('suspend_graduate'))
                    ->form([
                        Forms\Components\Textarea::make('note_ar')
                            ->label(__('sanabel.graduation.decision_note'))->rows(3)->required(),
                    ])
                    ->action(fn (GraduationRequest $record, array $data) => static::run(
                        fn () => app(GraduationService::class)->refuse(
                            $record, auth()->user(), $data['note_ar'] ?? '',
                        ),
                        __('sanabel.graduation.refused'),
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
            'index' => Pages\ListGraduationRequests::route('/'),
            'view' => Pages\ViewGraduationRequest::route('/{record}'),
        ];
    }
}
