<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GiftCardResource\Pages;
use App\Models\GiftCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-241 — «اختيار بطاقة الإهداء من التصاميم المعتمدة», 3 November.
 *
 * «المعتمدة» is the whole point: the list is the association's, filled and
 * reordered here, and a design withdrawn today stays on the cards already
 * issued with it.
 */
class GiftCardResource extends Resource
{
    protected static ?string $model = GiftCard::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.content');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.gift.design_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.gift.design_plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name_ar')
                ->label(__('sanabel.gift.design_name'))->required(),
            Forms\Components\TextInput::make('sort_order')
                ->label(__('sanabel.gift.design_sort'))->numeric()->minValue(0)->default(0),
            Forms\Components\Toggle::make('is_published')
                ->label(__('sanabel.gift.design_published'))->default(true),
            // The public disk, like the banners: artwork, not a family's papers.
            Forms\Components\FileUpload::make('image_path')
                ->label(__('sanabel.gift.design_image'))
                ->image()
                ->disk('public')
                ->directory('gift-cards')
                ->maxSize(4096)
                ->columnSpanFull(),

            /*
             | T-264 — «كل عبارة بتكون عندنا بلونين وليس لون واحد».
             |
             | Optional: a phrase with one colour is left exactly as it was,
             | and the giver is then shown no switch at all.
             */
            Forms\Components\FileUpload::make('image_path_alt')
                ->label(__('sanabel.gift.image_alt'))
                ->helperText(__('sanabel.gift.image_alt_help'))
                ->image()
                ->disk('public')
                ->directory('gift-cards')
                ->maxSize(4096)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('variant_ar')
                ->label(__('sanabel.gift.variant_label'))
                ->helperText(__('sanabel.gift.variant_label_help'))
                ->maxLength(40),

            Forms\Components\TextInput::make('variant_alt_ar')
                ->label(__('sanabel.gift.variant_alt_label'))
                ->maxLength(40),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label(__('sanabel.gift.design_image'))->disk('public'),
                Tables\Columns\ImageColumn::make('image_path_alt')
                    ->label(__('sanabel.gift.image_alt'))->disk('public'),
                Tables\Columns\TextColumn::make('name_ar')
                    ->label(__('sanabel.gift.design_name'))->searchable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('sanabel.gift.design_published'))->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('sanabel.gift.design_sort'))->sortable(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('sort_order')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftCards::route('/'),
            'create' => Pages\CreateGiftCard::route('/create'),
            'edit' => Pages\EditGiftCard::route('/{record}/edit'),
        ];
    }
}
