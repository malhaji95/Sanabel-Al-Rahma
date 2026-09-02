<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages;
use App\Models\Member;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Memberships are separate from donations; their money never funds a family. */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.member.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.member.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('membership_no')->label(__('sanabel.member.number'))->required(),
            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.member.name'))->required(),
            Forms\Components\TextInput::make('category')->label(__('sanabel.member.category'))->required(),
            Forms\Components\Select::make('status')->label(__('sanabel.beneficiary.status'))->options(__('sanabel.member_status'))->required(),
            Forms\Components\DatePicker::make('joined_at')->label(__('sanabel.member.joined_at'))->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('membership_no')->label(__('sanabel.member.number'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name_ar')->label(__('sanabel.member.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category')->label(__('sanabel.member.category'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('sanabel.beneficiary.status'))->badge(),
                Tables\Columns\TextColumn::make('joined_at')->label(__('sanabel.member.joined_at'))->date()->sortable(),
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
            'index' => Pages\ListMembers::route('/'),
            'create' => Pages\CreateMember::route('/create'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
