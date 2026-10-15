<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContentCardResource\Pages;
use App\Models\ContentCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\File;

/**
 * T-89 — the home page, as rows the association edits.
 *
 * Every band of the page is here: the two offers under the hero, the ways to
 * give, the promises, and the footer's links. A card is a title, a line, a
 * picture or an icon, and a destination — so a new way to give is an insert
 * rather than a release.
 */
class ContentCardResource extends Resource
{
    protected static ?string $model = ContentCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.content_card.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.content_card.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('block')
                ->label(__('sanabel.content_card.block'))
                ->options(collect(ContentCard::BLOCKS)
                    ->mapWithKeys(fn (string $b) => [$b => __('sanabel.content_card.blocks.'.$b)])
                    ->all())
                ->helperText(__('sanabel.content_card.block_help'))
                ->required()
                ->live(),

            Forms\Components\TextInput::make('title_ar')
                ->label(__('sanabel.content_card.title'))
                ->required(),

            Forms\Components\Textarea::make('body_ar')
                ->label(__('sanabel.content_card.body'))
                ->rows(2)
                ->columnSpanFull()
                // A partner is a logo and a name; the sentence belongs to the
                // cards above it, not to a row of logos.
                ->visible(fn (Forms\Get $get) => ! in_array($get('block'), ['footer_link', 'nav_link', 'partner'], true)),

            Forms\Components\TextInput::make('link')
                ->label(__('sanabel.content_card.link'))
                ->helperText(__('sanabel.content_card.link_help')),

            Forms\Components\TextInput::make('badge_ar')
                ->label(__('sanabel.content_card.badge'))
                ->helperText(__('sanabel.content_card.badge_help'))
                ->visible(fn (Forms\Get $get) => in_array($get('block'), ['feature', 'tile'], true)),

            /*
             | The icon is drawn when there is no picture, so a card is never
             | a grey rectangle while the association looks for artwork. The
             | list is read from the folder itself: dropping a new SVG in makes
             | it choosable, with nothing to register.
             */
            Forms\Components\Select::make('icon')
                ->label(__('sanabel.content_card.icon'))
                ->options(fn () => collect(File::glob(public_path('icons/*.svg')))
                    ->mapWithKeys(fn (string $path) => [basename($path, '.svg') => basename($path, '.svg')])
                    ->all())
                ->searchable()
                ->helperText(__('sanabel.content_card.icon_help'))
                ->visible(fn (Forms\Get $get) => ! in_array($get('block'), ['footer_link', 'nav_link'], true)),

            // Marketing artwork on the public disk — deliberately not the media
            // disk, which holds family documents behind signed URLs.
            Forms\Components\FileUpload::make('image')
                ->label(__('sanabel.content_card.image'))
                ->helperText(__('sanabel.content_card.image_help'))
                ->image()
                ->disk('public')
                ->directory('home')
                ->maxSize(4096)
                ->columnSpanFull()
                ->visible(fn (Forms\Get $get) => ! in_array($get('block'), ['footer_link', 'nav_link'], true)),

            Forms\Components\Toggle::make('is_published')
                ->label(__('sanabel.content_card.is_published'))
                ->default(true),

            Forms\Components\TextInput::make('sort_order')
                ->label(__('sanabel.content_card.sort_order'))
                ->numeric()
                ->minValue(0)
                ->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('block')
                    ->label(__('sanabel.content_card.block'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.content_card.blocks.'.$state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.content_card.title'))->searchable(),
                Tables\Columns\IconColumn::make('is_published')->label(__('sanabel.content_card.is_published'))->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label(__('sanabel.content_card.sort_order'))->numeric()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('block')
                    ->label(__('sanabel.content_card.block'))
                    ->options(collect(ContentCard::BLOCKS)
                        ->mapWithKeys(fn (string $b) => [$b => __('sanabel.content_card.blocks.'.$b)])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('sort_order')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCards::route('/'),
            'create' => Pages\CreateContentCard::route('/create'),
            'edit' => Pages\EditContentCard::route('/{record}/edit'),
        ];
    }
}
