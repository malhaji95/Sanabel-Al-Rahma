<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChangeRequestResource\Pages;
use App\Models\ChangeRequest;
use App\Services\CaseService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** T-13 — post-approval edits go through review. A material field forces a recompute. */
class ChangeRequestResource extends Resource
{
    protected static ?string $model = ChangeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.assessment');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.change_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.change_request.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('entity_type')->label(__('sanabel.change_request.entity'))->disabled(),
            Forms\Components\TextInput::make('entity_id')->label(__('sanabel.change_request.entity_id'))->disabled(),
            Forms\Components\KeyValue::make('old_json')->label(__('sanabel.change_request.before'))->disabled()->columnSpanFull(),
            Forms\Components\KeyValue::make('payload_json')->label(__('sanabel.change_request.after'))->disabled()->columnSpanFull(),
            Forms\Components\Textarea::make('reason_ar')->label(__('sanabel.actions.reason'))->disabled()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entity_type')
                    ->label(__('sanabel.change_request.entity'))
                    ->formatStateUsing(fn (string $state) => class_basename($state)),
                Tables\Columns\TextColumn::make('entity_id')->label(__('sanabel.change_request.entity_id')),
                Tables\Columns\IconColumn::make('is_material')->label(__('sanabel.change_request.is_material'))->boolean(),
                Tables\Columns\TextColumn::make('requester.name')->label(__('sanabel.change_request.requested_by')),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
                Tables\Columns\TextColumn::make('created_at')->label(__('sanabel.change_request.requested_at'))->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.change_request_status'))
                    ->default('pending'),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label(__('sanabel.actions.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ChangeRequest $record) => $record->status === 'pending'
                        && auth()->user()->can_('approve_change'))
                    ->requiresConfirmation()
                    ->action(function (ChangeRequest $record) {
                        app(CaseService::class)->approveChange($record, auth()->user());

                        Notification::make()->title(__('sanabel.actions.approved'))->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label(__('sanabel.actions.reject'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (ChangeRequest $record) => $record->status === 'pending'
                        && auth()->user()->can_('approve_change'))
                    ->form([Forms\Components\Textarea::make('note_ar')->label(__('sanabel.actions.reason'))->required()])
                    ->action(function (ChangeRequest $record, array $data) {
                        app(CaseService::class)->rejectChange($record, auth()->user(), $data['note_ar']);

                        Notification::make()->title(__('sanabel.actions.rejected'))->danger()->send();
                    }),

                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChangeRequests::route('/'),
            'view' => Pages\ViewChangeRequest::route('/{record}'),
        ];
    }
}
