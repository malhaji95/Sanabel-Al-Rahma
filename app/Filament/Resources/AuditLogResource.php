<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The audit log, read.
 *
 * Rule 4 has written this table since the first week: who, what, on which
 * record, before and after, and when. Until now there was no screen to read
 * it from, which the association noticed during the walkthrough.
 *
 * Nothing here writes. The model refuses an update or a delete, and the
 * policy refuses both again.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.audit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.audit.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string,string> role key => Arabic name, read once. */
    public static function roleNames(): array
    {
        static $names = null;

        return $names ??= Role::query()->pluck('name_ar', 'key')->all();
    }

    /** `App\Models\Beneficiary` reads as nothing to anyone but us. */
    public static function entityLabel(?string $class): string
    {
        $key = 'sanabel.audit.entities.'.class_basename((string) $class);

        return __($key) === $key ? class_basename((string) $class) : __($key);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('sanabel.audit.when'))->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label(__('sanabel.audit.actor'))
                    ->placeholder(__('sanabel.audit.system'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('actor_role')
                    ->label(__('sanabel.user.role'))
                    ->badge()
                    // Roles are rows, so their Arabic names come from the table
                    // rather than a second list that would drift from it.
                    ->formatStateUsing(fn (?string $state) => static::roleNames()[$state] ?? $state ?? '—'),
                Tables\Columns\TextColumn::make('action')
                    ->label(__('sanabel.audit.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.audit.actions.'.$state) === 'sanabel.audit.actions.'.$state
                        ? $state
                        : __('sanabel.audit.actions.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('entity_type')
                    ->label(__('sanabel.audit.entity'))
                    ->formatStateUsing(fn (?string $state) => static::entityLabel($state)),
                Tables\Columns\TextColumn::make('entity_id')
                    ->label(__('sanabel.audit.entity_id')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->label(__('sanabel.audit.action'))
                    ->options([
                        'created' => __('sanabel.audit.actions.created'),
                        'updated' => __('sanabel.audit.actions.updated'),
                        'deleted' => __('sanabel.audit.actions.deleted'),
                        'restored' => __('sanabel.audit.actions.restored'),
                    ]),

                Tables\Filters\SelectFilter::make('entity_type')
                    ->label(__('sanabel.audit.entity'))
                    ->options(fn () => AuditLog::query()
                        ->distinct()
                        ->pluck('entity_type')
                        ->mapWithKeys(fn (?string $c) => [$c => static::entityLabel($c)])
                        ->all()),

                Tables\Filters\SelectFilter::make('actor_id')
                    ->label(__('sanabel.audit.actor'))
                    ->options(fn () => User::query()->pluck('name', 'id')->all())
                    ->searchable(),

                Tables\Filters\Filter::make('when')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label(__('sanabel.audit.from')),
                        \Filament\Forms\Components\DatePicker::make('until')->label(__('sanabel.audit.until')),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label(__('sanabel.audit.before_after'))])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('sanabel.audit.before'))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('before_json')
                        ->label('')
                        ->keyLabel(__('sanabel.audit.field'))
                        ->valueLabel(__('sanabel.audit.value')),
                ])
                ->visible(fn (AuditLog $record) => filled($record->before_json)),

            Infolists\Components\Section::make(__('sanabel.audit.after'))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('after_json')
                        ->label('')
                        ->keyLabel(__('sanabel.audit.field'))
                        ->valueLabel(__('sanabel.audit.value')),
                ])
                ->visible(fn (AuditLog $record) => filled($record->after_json)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
