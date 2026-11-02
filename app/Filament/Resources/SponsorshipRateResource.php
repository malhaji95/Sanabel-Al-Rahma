<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SponsorshipRateResource\Pages;
use App\Models\SponsorshipRate;
use App\Services\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-210 — the price of a sponsorship, by kind.
 *
 * «تحديد مبلغ كل نوع من الكفالات يكون من صلاحيات الإدارة وفق ضوابط معتمدة»,
 * 1 November. The screen that holds that authority: one row per kind, with
 * the monthly amount and the duration the association offers, and the hand
 * and the date behind every change (rule 4).
 *
 * `edit_config` is the key, which is the same door the rest of the
 * association's own figures sit behind — both directors, their deputies and
 * the administrator.
 */
class SponsorshipRateResource extends Resource
{
    protected static ?string $model = SponsorshipRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.money');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.sponsorship_rate.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.sponsorship_rate.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can_('edit_config') ?? false;
    }

    /** The doors the association publishes, and nothing else. */
    public static function kindOptions(): array
    {
        return collect((array) config('sanabel.sponsorship_kinds'))
            ->mapWithKeys(fn (string $key) => [$key => __('sanabel.sponsorship_kind.'.$key)])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('kind')
                ->label(__('sanabel.sponsorship_rate.kind'))
                ->options(fn () => static::kindOptions())
                ->selectablePlaceholder(false)
                ->required()
                // One published price per kind, held by the database too.
                ->unique(ignoreRecord: true),

            Forms\Components\TextInput::make('amount')
                ->label(__('sanabel.sponsorship_rate.amount'))
                ->helperText(__('sanabel.sponsorship_rate.amount_help'))
                ->numeric()->minValue(1)->required()
                ->suffix(Money::short()),

            Forms\Components\TextInput::make('months')
                ->label(__('sanabel.sponsorship_rate.months'))
                ->helperText(__('sanabel.sponsorship_rate.months_help'))
                ->numeric()->minValue(1)->required(),

            Forms\Components\Toggle::make('is_active')
                ->label(__('sanabel.sponsorship_rate.is_active'))
                ->helperText(__('sanabel.sponsorship_rate.is_active_help'))
                ->default(true),

            Forms\Components\Textarea::make('notes_ar')
                ->label(__('sanabel.sponsorship_rate.notes'))
                ->helperText(__('sanabel.sponsorship_rate.notes_help'))
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('sanabel.sponsorship_rate.kind'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.sponsorship_kind.'.$state)),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('sanabel.sponsorship_rate.amount'))
                    ->state(fn (SponsorshipRate $record) => number_format($record->amount).' '.Money::short($record->currency)),

                Tables\Columns\TextColumn::make('months')
                    ->label(__('sanabel.sponsorship_rate.months'))
                    ->state(fn (SponsorshipRate $record) => __('sanabel.sponsorship_rate.months_value', ['count' => $record->months])),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('sanabel.sponsorship_rate.is_active'))->boolean(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label(__('sanabel.sponsorship_rate.set_by'))->placeholder('—'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('sanabel.sponsorship_rate.set_at'))->dateTime('Y-m-d H:i'),
            ])
            ->emptyStateHeading(__('sanabel.sponsorship_rate.empty'))
            ->emptyStateDescription(__('sanabel.sponsorship_rate.empty_help'))
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('kind')
            ->paginated([25, 50]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSponsorshipRates::route('/'),
            'create' => Pages\CreateSponsorshipRate::route('/create'),
            'edit' => Pages\EditSponsorshipRate::route('/{record}/edit'),
        ];
    }
}
