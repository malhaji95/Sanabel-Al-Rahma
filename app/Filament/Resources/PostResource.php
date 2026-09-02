<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.post.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.post.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')->label(__('sanabel.post.slug'))->required(),
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.post.title'))->required(),
            Forms\Components\Textarea::make('body_ar')->label(__('sanabel.post.body'))->columnSpanFull(),
            Forms\Components\Toggle::make('is_published')->label(__('sanabel.post.is_published')),
            Forms\Components\TextInput::make('sort_order')->label(__('sanabel.post.sort_order'))->numeric()->minValue(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.post.title'))->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.post.is_published'))->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label(__('sanabel.post.sort_order'))->numeric()->sortable(),
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
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
