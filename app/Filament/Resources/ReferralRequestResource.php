<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralRequestResource\Pages;
use App\Models\Provider;
use App\Models\ReferralRequest;
use App\Services\HealthPathService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-145 — «ونحتاج يكون عنده تقديم مسار الصحي اللي هو تقديم على اطباء او مشافي
 * او صيدلية», 28 October.
 *
 * The queue in front of the referral cards: a household asked for a doctor, a
 * hospital or a pharmacy, and this is where the association answers — with
 * the card it has always issued, or with a refusal that carries its reason.
 */
class ReferralRequestResource extends Resource
{
    protected static ?string $model = ReferralRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $slug = 'health-path-requests';

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.modules');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.health_path.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.health_path.plural');
    }

    /** The open askings — the number somebody acts on. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('beneficiary_id')
                ->label(__('sanabel.referral.case'))
                ->relationship('beneficiary', 'file_number')
                ->searchable()->preload()->required(),

            Forms\Components\Select::make('provider_type')
                ->label(__('sanabel.health_path.provider_type'))
                ->options(fn () => collect(ReferralRequest::TYPES)
                    ->mapWithKeys(fn (string $type) => [$type => __('sanabel.provider_type.'.$type)])
                    ->all())
                ->required(),

            Forms\Components\TextInput::make('specialty_ar')
                ->label(__('sanabel.health_path.specialty')),

            Forms\Components\Textarea::make('note_ar')
                ->label(__('sanabel.health_path.note'))
                ->helperText(__('sanabel.health_path.note_help'))
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('beneficiary.file_number')
                    ->label(__('sanabel.referral.case'))
                    ->searchable()
                    ->url(fn (ReferralRequest $record) => $record->beneficiary
                        && auth()->user()->can('view', $record->beneficiary)
                            ? BeneficiaryResource::getUrl('view', ['record' => $record->beneficiary_id])
                            : null)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('provider_type')
                    ->label(__('sanabel.health_path.provider_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.provider_type.'.$state)),

                Tables\Columns\TextColumn::make('specialty_ar')
                    ->label(__('sanabel.health_path.specialty'))->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.health_path.statuses.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'issued' => 'success',
                        'declined' => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('referral.code')
                    ->label(__('sanabel.referral.code'))->placeholder('—')->copyable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('sanabel.health_path.applied_at'))->dateTime('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('handler.name')
                    ->label(__('sanabel.health_path.handled_by'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(fn () => collect(ReferralRequest::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __('sanabel.health_path.statuses.'.$s)])
                        ->all())
                    ->default('open'),

                Tables\Filters\SelectFilter::make('provider_type')
                    ->label(__('sanabel.health_path.provider_type'))
                    ->options(fn () => collect(ReferralRequest::TYPES)
                        ->mapWithKeys(fn (string $type) => [$type => __('sanabel.provider_type.'.$type)])
                        ->all()),
            ])
            ->actions([
                /*
                 | The answer. The provider list is narrowed to the kind that
                 | was asked for, because a card to a pharmacy answers nothing
                 | a household asked a hospital for.
                 */
                Tables\Actions\Action::make('issue')
                    ->label(__('sanabel.health_path.issue'))
                    ->icon('heroicon-o-ticket')
                    ->color('success')
                    ->visible(fn (ReferralRequest $record) => auth()->user()->can('handle', $record))
                    ->form(fn (ReferralRequest $record) => [
                        /*
                         | T-195 — «لازم تظهر الخيارات وممكن أختار منها بدون
                         | بحث», 29 October, over a photograph of this very
                         | field reading «ابدأ بالكتابة للبحث...».
                         |
                         | That sentence is Filament's words for a list with
                         | nothing in it, not an instruction. The list is
                         | empty when no provider of the asked-for kind is
                         | active, so it now says that; and the search box is
                         | offered only once the list is long enough to need
                         | one.
                         */
                        Forms\Components\Select::make('provider_id')
                            ->label(__('sanabel.referral.provider'))
                            ->options($providers = Provider::where('type', $record->provider_type)
                                ->where('status', 'active')
                                ->pluck('name_ar', 'id'))
                            ->helperText($providers->isEmpty()
                                ? __('sanabel.health_path.no_provider_of_type')
                                : __('sanabel.health_path.provider_help'))
                            ->searchable($providers->count() > 10)
                            ->required(),
                    ])
                    ->action(function (ReferralRequest $record, array $data) {
                        try {
                            $provider = Provider::findOrFail($data['provider_id']);

                            $referral = app(HealthPathService::class)
                                ->issue($record, $provider, auth()->user());

                            Notification::make()
                                ->title(__('sanabel.health_path.issued', ['code' => $referral->code]))
                                ->success()->send();

                            // The association chose a warning rather than a
                            // block when a provider's agreed number is passed.
                            if ($provider->refresh()->quotaExceeded()) {
                                Notification::make()
                                    ->title(__('sanabel.provider.quota_exceeded'))
                                    ->warning()->persistent()->send();
                            }
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('decline')
                    ->label(__('sanabel.health_path.decline'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReferralRequest $record) => auth()->user()->can('handle', $record))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('sanabel.health_path.decline_reason'))
                            ->helperText(__('sanabel.health_path.decline_reason_help'))
                            ->required()->rows(2),
                    ])
                    ->action(function (ReferralRequest $record, array $data) {
                        try {
                            app(HealthPathService::class)
                                ->decline($record, auth()->user(), $data['reason']);

                            Notification::make()->title(__('sanabel.health_path.declined'))->warning()->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralRequests::route('/'),
            'create' => Pages\CreateReferralRequest::route('/create'),
        ];
    }
}
