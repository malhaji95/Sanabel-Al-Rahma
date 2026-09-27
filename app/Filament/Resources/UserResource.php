<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Role;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/** Roles are rows — adding one later is a data insert, not a rewrite. */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.user.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.user.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('sanabel.user.name'))->required(),
            Forms\Components\TextInput::make('email')->label(__('sanabel.user.email'))->email()->required()->unique(ignoreRecord: true),

            Forms\Components\TextInput::make('password')
                ->label(__('sanabel.user.password'))
                ->password()
                ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create'),

            Forms\Components\Select::make('role_id')
                ->label(__('sanabel.user.role'))
                ->options(fn () => Role::pluck('name_ar', 'id'))
                ->live()
                ->required(),

            Forms\Components\Select::make('region_id')
                ->label(__('sanabel.beneficiary.region'))
                ->relationship('region', 'name_ar')
                ->searchable()
                ->helperText(__('sanabel.user.region_help')),

            Forms\Components\Toggle::make('is_active')->label(__('sanabel.user.is_active'))->default(true),

            // Only an association routes donations, so these two stay hidden
            // for every other role rather than sitting there meaningless.
            Forms\Components\TextInput::make('wallet_encrypted')
                ->label(__('sanabel.user.wallet'))
                ->helperText(__('sanabel.user.wallet_help'))
                ->visible(fn (Forms\Get $get) => self::isAssociation($get('role_id'))),

            Forms\Components\Select::make('transfer_mode')
                ->label(__('sanabel.transfer.mode'))
                ->options(__('sanabel.transfer.modes'))
                ->helperText(__('sanabel.transfer.mode_help'))
                ->visible(fn (Forms\Get $get) => self::isAssociation($get('role_id'))),
        ])->columns(2);
    }

    /** The association role is data, so it is looked up rather than hardcoded by id. */
    private static function isAssociation(mixed $roleId): bool
    {
        return $roleId && Role::whereKey($roleId)->value('key') === 'association';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('sanabel.user.name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label(__('sanabel.user.email'))->searchable(),
                Tables\Columns\TextColumn::make('role.name_ar')->label(__('sanabel.user.role'))->badge(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region')),
                Tables\Columns\IconColumn::make('is_active')->label(__('sanabel.user.is_active'))->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role_id')
                    ->label(__('sanabel.user.role'))
                    ->options(fn () => Role::pluck('name_ar', 'id')),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
