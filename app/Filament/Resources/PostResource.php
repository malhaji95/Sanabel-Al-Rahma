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
            Forms\Components\TextInput::make('title_ar')->label(__('sanabel.post.title'))->required(),
            Forms\Components\TextInput::make('slug')->label(__('sanabel.post.slug'))
                ->helperText(__('sanabel.post.slug_help'))
                ->required(),

            Forms\Components\Textarea::make('excerpt_ar')
                ->label(__('sanabel.post.excerpt'))
                ->helperText(__('sanabel.post.excerpt_help'))
                ->rows(2)
                ->maxLength(300)
                ->columnSpanFull(),

            // Marketing artwork on the public disk — deliberately not the media
            // disk, which holds family documents behind signed URLs.
            Forms\Components\FileUpload::make('image')
                ->label(__('sanabel.post.image'))
                ->helperText(__('sanabel.post.image_help'))
                ->image()
                ->disk('public')
                ->directory('news')
                ->maxSize(4096)
                ->columnSpanFull(),

            // Pictures dropped inside the article land on the same disk. The
            // model strips anything the toolbar cannot produce before saving.
            Forms\Components\RichEditor::make('body_ar')
                ->label(__('sanabel.post.body'))
                ->fileAttachmentsDisk('public')
                ->fileAttachmentsDirectory('news')
                ->columnSpanFull(),

            Forms\Components\DateTimePicker::make('published_at')
                ->label(__('sanabel.post.published_at'))
                ->helperText(__('sanabel.post.published_at_help'))
                ->seconds(false),
            Forms\Components\TextInput::make('sort_order')->label(__('sanabel.post.sort_order'))->numeric()->minValue(0),

            // The path, not a switch. Moving to 'published' is what puts the
            // piece on the public site; the model keeps the old flag in step.
            //
            // An editor may carry a piece as far as review. The two steps that
            // put it in front of a reader are left visible but unselectable,
            // so the account that writes is never the account that approves.
            Forms\Components\Select::make('status')
                ->label(__('sanabel.post.status'))
                ->helperText(__('sanabel.post.status_help'))
                ->options(collect(Post::STATUSES)
                    ->mapWithKeys(fn (string $s) => [$s => __('sanabel.post.statuses.'.$s)])
                    ->all())
                ->disableOptionWhen(fn (string $value) => in_array($value, Post::SIGNED_OFF, true)
                    && ! Post::canBeApprovedBy(auth()->user()))
                ->default('draft')
                ->required()
                ->live(),

            Forms\Components\Section::make(__('sanabel.post.consent'))
                ->description(__('sanabel.post.consent_help'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Select::make('beneficiary_id')
                        ->label(__('sanabel.post.beneficiary'))
                        ->helperText(__('sanabel.post.beneficiary_help'))
                        ->relationship('beneficiary', 'file_number')
                        ->searchable()
                        ->preload()
                        ->live(),

                    Forms\Components\TextInput::make('consent_signed_by_ar')
                        ->label(__('sanabel.post.consent_signed_by'))
                        ->required(fn (Forms\Get $get) => filled($get('beneficiary_id'))),

                    Forms\Components\DatePicker::make('consent_signed_on')
                        ->label(__('sanabel.post.consent_signed_on'))
                        ->required(fn (Forms\Get $get) => filled($get('beneficiary_id'))),

                    Forms\Components\FileUpload::make('consent_media_id')
                        ->label(__('sanabel.post.consent_document'))
                        ->disk('public')
                        ->directory('consents')
                        ->required(fn (Forms\Get $get) => filled($get('beneficiary_id'))),
                ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(__('sanabel.post.image'))->disk('public'),
                Tables\Columns\TextColumn::make('title_ar')->label(__('sanabel.post.title'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('published_at')->label(__('sanabel.post.published_at'))->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.post.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.post.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'approved' => 'info',
                        'review' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('approvedBy.name')
                    ->label(__('sanabel.post.approved_by'))
                    ->placeholder(__('sanabel.post.not_approved_yet'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sort_order')->label(__('sanabel.post.sort_order'))->numeric()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label(__('sanabel.post.preview'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (Post $record) => route('post.preview', $record->slug))
                    ->openUrlInNewTab(),
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
