<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.campaign.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.campaign.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.campaign.title'))->required(),
            Forms\Components\Textarea::make('body_ar')->label(__('sanabel.campaign.body'))->columnSpanFull(),
            Forms\Components\TextInput::make('goal_amount')->label(__('sanabel.campaign.goal'))->numeric()->minValue(0)->required()->suffix(config('sanabel.currency')),
            Forms\Components\Select::make('beneficiary_id')->label(__('sanabel.campaign.case'))->relationship('beneficiary', 'file_number')->searchable(),
            Forms\Components\Textarea::make('surplus_policy_text_ar')
                ->label(__('sanabel.campaign.surplus_policy'))
                ->helperText(__('sanabel.campaign.surplus_help'))
                // Mandatory before publishing, and shown to the donor before payment.
                ->required(fn (Forms\Get $get) => (bool) $get('is_published'))
                ->columnSpanFull(),
            Forms\Components\Toggle::make('is_published')->label(__('sanabel.campaign.is_published')),
            Forms\Components\Select::make('status')->label(__('sanabel.beneficiary.status'))->options(__('sanabel.campaign_status'))->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.campaign.title'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('goal_amount')->label(__('sanabel.campaign.goal'))->numeric()->sortable(),
                // Derived, not stored, so neither is sortable in SQL.
                Tables\Columns\TextColumn::make('collected')
                    ->label(__('sanabel.campaign.collected'))
                    ->state(fn (Campaign $record) => $record->collectedAmount())
                    ->numeric(),
                Tables\Columns\TextColumn::make('reserved')
                    ->label(__('sanabel.campaign.reserved'))
                    ->state(fn (Campaign $record) => $record->reservedAmount())
                    ->numeric(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.campaign_status.'.$state))
                    ->badge(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.campaign.is_published'))->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Funding closes by itself when the goal is met. Moving the
                // money and proving it arrived are human steps, which is why a
                // funded campaign never completes on its own.
                Tables\Actions\Action::make('execute')
                    ->label(__('sanabel.campaign.start_execution'))
                    ->icon('heroicon-o-truck')
                    ->visible(fn (Campaign $record) => $record->status === 'funded')
                    ->authorize(fn (Campaign $record) => auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn (Campaign $record) => $record->forceFill(['status' => 'awaiting_execution'])->save()),

                Tables\Actions\Action::make('complete')
                    ->label(__('sanabel.campaign.complete'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Campaign $record) => $record->status === 'awaiting_execution')
                    ->authorize(fn (Campaign $record) => auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.campaign.complete_help'))
                    ->action(fn (Campaign $record) => $record->forceFill(['status' => 'completed'])->save()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
