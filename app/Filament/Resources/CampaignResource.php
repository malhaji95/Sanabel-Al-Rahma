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
                Tables\Columns\TextColumn::make('collected_amount')->label(__('sanabel.campaign.collected'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('reserved_amount')->label(__('sanabel.campaign.reserved'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.campaign.is_published'))->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
