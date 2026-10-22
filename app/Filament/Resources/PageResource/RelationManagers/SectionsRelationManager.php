<?php

namespace App\Filament\Resources\PageResource\RelationManagers;

use App\Models\PageSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * T-113 — the page's three levels, as rows the association edits.
 *
 * «مع إمكانية تعديل محتوى المستويات الثلاثة من لوحة الإدارة», 19 October.
 *
 * A relation manager rather than a repeater on the page form: there are
 * twenty-odd sections on «كيف نعمل», they are reordered by dragging, and a
 * repeater that long is a page that saves everything to change one sentence.
 *
 * Which boxes appear depends on the kind, because a card has no control line
 * and a statement has no link, and a form that asks for both of those on
 * every row teaches the editor to ignore most of it.
 */
class SectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sanabel.page_section.plural');
    }

    /** The kinds that carry each optional box. */
    private const WITH_RULE = ['accordion'];

    private const WITH_LINK = ['accordion', 'reference', 'document', 'card'];

    private const WITH_TITLE = ['heading', 'group', 'card', 'accordion', 'reference', 'document', 'note', 'funding', 'person'];

    /** T-118 — the one kind that carries a face. */
    private const WITH_IMAGE = ['person'];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label(__('sanabel.page_section.kind'))
                ->helperText(__('sanabel.page_section.kind_help'))
                ->options(collect(PageSection::KINDS)
                    ->mapWithKeys(fn (string $kind) => [$kind => __('sanabel.page_section.kinds.'.$kind)])
                    ->all())
                ->required()
                ->live()
                ->columnSpanFull(),

            Forms\Components\TextInput::make('title_ar')
                ->label(__('sanabel.page_section.title'))
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_TITLE, true))
                ->columnSpanFull(),

            Forms\Components\Textarea::make('body_ar')
                ->label(__('sanabel.page_section.body'))
                ->helperText(__('sanabel.page_section.body_help'))
                ->rows(5)
                ->columnSpanFull(),

            /*
             | T-118 — a committee member's photograph, and the switch that
             | says whether it is published. «ممكن نقدر نخفي الصورة الي مابدو
             | ياه تظهر وتكون لوغو بدالها», 22 October.
             */
            Forms\Components\FileUpload::make('image')
                ->label(__('sanabel.page_section.image'))
                ->helperText(__('sanabel.page_section.image_help'))
                ->image()
                ->disk('public')
                ->directory('people')
                ->maxSize(4096)
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_IMAGE, true))
                ->columnSpanFull(),

            Forms\Components\Toggle::make('show_image')
                ->label(__('sanabel.page_section.show_image'))
                ->helperText(__('sanabel.page_section.show_image_help'))
                ->default(true)
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_IMAGE, true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('rule_ar')
                ->label(__('sanabel.page_section.rule'))
                ->helperText(__('sanabel.page_section.rule_help'))
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_RULE, true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('badge_ar')
                ->label(__('sanabel.page_section.badge'))
                ->helperText(__('sanabel.page_section.badge_help'))
                ->maxLength(60),

            Forms\Components\TextInput::make('anchor')
                ->label(__('sanabel.page_section.anchor'))
                ->helperText(__('sanabel.page_section.anchor_help'))
                ->maxLength(60),

            Forms\Components\TextInput::make('link')
                ->label(__('sanabel.page_section.link'))
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_LINK, true)),

            Forms\Components\TextInput::make('link_label_ar')
                ->label(__('sanabel.page_section.link_label'))
                ->visible(fn (Forms\Get $get) => in_array($get('kind'), self::WITH_LINK, true)),

            Forms\Components\TextInput::make('sort_order')
                ->label(__('sanabel.page_section.sort_order'))
                ->numeric()
                ->minValue(0)
                ->default(0),

            Forms\Components\Toggle::make('is_published')
                ->label(__('sanabel.page_section.is_published'))
                ->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('sanabel.page_section.sort_order'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('kind')
                    ->label(__('sanabel.page_section.kind'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.page_section.kinds.'.$state))
                    ->badge(),

                Tables\Columns\TextColumn::make('title_ar')
                    ->label(__('sanabel.page_section.title'))
                    // A statement has no title, so the first words of it are
                    // what tells an editor which row they are looking at.
                    ->formatStateUsing(fn (?string $state, PageSection $record) => $state
                        ?: Str::limit((string) $record->body_ar, 60))
                    ->wrap(),

                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('sanabel.page_section.is_published'))
                    ->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
