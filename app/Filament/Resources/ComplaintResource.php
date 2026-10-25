<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComplaintResource\Pages;
use App\Models\Complaint;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** T-33 — reference number, category, owner, status, resolution. Nothing more. */
class ComplaintResource extends Resource
{
    protected static ?string $model = Complaint::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-ellipsis';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.complaint.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.complaint.plural');
    }

    /**
     * T-134 — «مشرف المنطقة ما الشكاوي التي تظهر عنده … حتى المندوب كذلك؟»,
     * 28 October.
     *
     * Every one of them, as it turned out. The screen opens for whoever may
     * act on what is on it (T-115), and `file_complaint` is held by a
     * delegate, an area supervisor, a partner association and a donor — so
     * the list showed all of them every complaint in the association,
     * including the ones families filed about their own payments.
     *
     * Handling a complaint is `handle_complaint`, which belongs to the
     * executive director and the oversight committee. An account that only
     * files one sees the ones it filed, and the ones filed from its own
     * portal account, and nothing else. The board of trustees and the
     * chairman oversee, so they read them all as before.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->can_('handle_complaint')
            || $user?->isAdmin()
            || $user?->hasRole('council', 'board_director', 'deputy_board_director')) {
            return $query;
        }

        return $query->where(fn (Builder $mine) => $mine
            ->where('created_by', $user?->getKey())
            ->orWhere('submitted_by', $user?->getKey()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('reference_no')
                ->label(__('sanabel.complaint.reference_no'))
                ->disabled()->dehydrated()
                ->default(fn () => 'C-'.now()->format('ymd').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT)),

            Forms\Components\TextInput::make('subject_ar')->label(__('sanabel.complaint.subject'))->required(),
            Forms\Components\Select::make('category')->label(__('sanabel.complaint.category'))->options(__('sanabel.complaint_category'))->required(),
            Forms\Components\Textarea::make('body_ar')->label(__('sanabel.complaint.body'))->columnSpanFull(),

            Forms\Components\Select::make('against_user_id')
                ->label(__('sanabel.complaint.against'))
                ->options(fn () => User::pluck('name', 'id'))
                ->searchable()->live(),

            // Rule 14 — the subject of a complaint is never offered as its owner.
            Forms\Components\Select::make('owner_id')
                ->label(__('sanabel.complaint.owner'))
                ->options(fn (Forms\Get $get) => User::when(
                    $get('against_user_id'),
                    fn ($q, $id) => $q->whereKeyNot($id)
                )->pluck('name', 'id'))
                ->searchable(),

            Forms\Components\Select::make('status')->label(__('sanabel.beneficiary.status'))->options(__('sanabel.complaint_status'))->required(),
            Forms\Components\Textarea::make('resolution_ar')->label(__('sanabel.complaint.resolution'))->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference_no')->label(__('sanabel.complaint.reference_no'))->searchable()->copyable(),
                Tables\Columns\TextColumn::make('subject_ar')->label(__('sanabel.complaint.subject'))->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('sanabel.complaint.category'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.complaint_category.'.$state))
                    ->badge(),
                Tables\Columns\TextColumn::make('owner.name')->label(__('sanabel.complaint.owner')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.complaint_status.'.$state))
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')->label(__('sanabel.complaint.created_at'))->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(__('sanabel.complaint_status')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComplaints::route('/'),
            'create' => Pages\CreateComplaint::route('/create'),
            'edit' => Pages\EditComplaint::route('/{record}/edit'),
        ];
    }
}
