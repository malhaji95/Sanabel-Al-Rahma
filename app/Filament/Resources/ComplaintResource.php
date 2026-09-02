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
                Tables\Columns\TextColumn::make('category')->label(__('sanabel.complaint.category'))->badge(),
                Tables\Columns\TextColumn::make('owner.name')->label(__('sanabel.complaint.owner')),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
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
