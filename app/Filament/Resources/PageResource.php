<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.page.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.page.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->label(__('sanabel.page.slug'))->required(),
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.page.title'))->required(),
            Forms\Components\Textarea::make('body_ar')->label(__('sanabel.page.body'))->columnSpanFull(),
            Forms\Components\Toggle::make('is_published')->label(__('sanabel.page.is_published')),
            Forms\Components\TextInput::make('sort_order')->label(__('sanabel.page.sort_order'))->numeric()->minValue(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.page.title'))->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.page.is_published'))->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label(__('sanabel.page.sort_order'))->numeric()->sortable(),
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
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
