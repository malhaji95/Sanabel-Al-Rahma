<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.banner.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.banner.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.banner.title'))->required(),
            Forms\Components\Textarea::make('body_ar')->label(__('sanabel.banner.body'))->columnSpanFull(),
            Forms\Components\Toggle::make('is_published')->label(__('sanabel.banner.is_published')),
            Forms\Components\TextInput::make('sort_order')->label(__('sanabel.banner.sort_order'))->numeric()->minValue(0),
            Forms\Components\TextInput::make('link')->label(__('sanabel.banner.link')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.banner.title'))->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.banner.is_published'))->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label(__('sanabel.banner.sort_order'))->numeric()->sortable(),
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
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
