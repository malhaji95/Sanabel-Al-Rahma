<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BeneficiaryResource\Pages;
use App\Models\Beneficiary;
use App\Models\Region;
use App\Services\CoverageService;
use App\Services\DependencyRules;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * T-10 — the beneficiary file, as a multi-step form.
 * Every computed value is read-only: no field on this form accepts a score.
 */
class BeneficiaryResource extends Resource
{
    protected static ?string $model = Beneficiary::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('sanabel.nav.beneficiaries');
    }

    public static function getModelLabel(): string
    {
        return __('sanabel.beneficiary.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sanabel.beneficiary.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Wizard::make([
                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_identity'))->schema([
                    Forms\Components\TextInput::make('file_number')
                        ->label(__('sanabel.beneficiary.file_number'))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->default(fn () => 'F-'.now()->format('y').'-'.str_pad((string) (Beneficiary::withoutGlobalScopes()->max('id') + 1), 6, '0', STR_PAD_LEFT)),

                    Forms\Components\TextInput::make('national_id_encrypted')
                        ->label(__('sanabel.beneficiary.national_id'))
                        ->required()
                        ->maxLength(64)
                        // Rule 11 — an exact match blocks a second file.
                        ->rule(fn (?Beneficiary $record) => function ($attribute, $value, $fail) use ($record) {
                            $existing = Beneficiary::withoutGlobalScopes()
                                ->where('national_id_hash', Beneficiary::hashNationalId((string) $value))
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($existing) {
                                $fail(__('sanabel.cases.duplicate_national_id'));
                            }
                        }),

                    Forms\Components\TextInput::make('first_name')->label(__('sanabel.beneficiary.first_name'))->required(),
                    Forms\Components\TextInput::make('father_name')->label(__('sanabel.beneficiary.father_name'))->required(),
                    Forms\Components\TextInput::make('family_name')->label(__('sanabel.beneficiary.family_name'))->required(),
                    Forms\Components\TextInput::make('phone_encrypted')->label(__('sanabel.beneficiary.phone'))->tel(),
                    Forms\Components\TextInput::make('wallet_encrypted')
                        ->label(__('sanabel.beneficiary.wallet'))
                        ->helperText(__('sanabel.beneficiary.wallet_optional')),

                    Forms\Components\Select::make('region_id')
                        ->label(__('sanabel.beneficiary.region'))
                        ->options(fn () => Region::where('is_active', true)->pluck('name_ar', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('marital_status')
                        ->label(__('sanabel.beneficiary.marital_status'))
                        ->options(__('sanabel.marital_status')),

                    Forms\Components\Select::make('support_type')
                        ->label(__('sanabel.beneficiary.support_type'))
                        ->options(__('sanabel.masked.need_type'))
                        ->required(),
                ])->columns(2),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_members'))->schema([
                    Forms\Components\Repeater::make('members')
                        ->relationship()
                        ->label(__('sanabel.beneficiary.members'))
                        ->schema([
                            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.member.name'))->required(),
                            Forms\Components\TextInput::make('relation')->label(__('sanabel.member.relation'))->required(),
                            Forms\Components\TextInput::make('birth_year')
                                ->label(__('sanabel.member.birth_year'))
                                ->numeric()->minValue(1900)->maxValue((int) date('Y'))
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Set $set) {
                                    if ($state) {
                                        $set('person_class', DependencyRules::personClass((int) date('Y') - (int) $state));
                                    }
                                }),
                            Forms\Components\Select::make('gender')
                                ->label(__('sanabel.member.gender'))
                                ->options(__('sanabel.gender'))->required(),

                            // Derived, never typed: each member falls under exactly one class.
                            Forms\Components\Select::make('person_class')
                                ->label(__('sanabel.member.person_class'))
                                ->options(__('sanabel.person_class'))
                                ->required()
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\Toggle::make('is_student')->label(__('sanabel.member.is_student'))->live(),
                            Forms\Components\Toggle::make('has_documented_condition')
                                ->label(__('sanabel.member.has_documented_condition'))
                                ->helperText(__('sanabel.member.condition_help'))
                                ->live(),

                            // Both flags are computed from the rules, not entered by hand.
                            Forms\Components\Placeholder::make('dependent_preview')
                                ->label(__('sanabel.member.dependent'))
                                ->content(fn (Forms\Get $get) => DependencyRules::isDependent(
                                    (int) date('Y') - (int) ($get('birth_year') ?: date('Y')),
                                    (bool) $get('is_student'),
                                    false,
                                ) ? __('sanabel.yes') : __('sanabel.no')),

                            Forms\Components\Placeholder::make('unable_preview')
                                ->label(__('sanabel.member.unable_to_earn'))
                                ->content(fn (Forms\Get $get) => DependencyRules::isUnableToEarn(
                                    (bool) $get('has_documented_condition')
                                ) ? __('sanabel.yes') : __('sanabel.no')),

                            Forms\Components\Textarea::make('notes_ar')->label(__('sanabel.member.notes'))->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::deriveMemberFlags($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::deriveMemberFlags($data)),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_income'))->schema([
                    Forms\Components\Repeater::make('incomes')
                        ->relationship()
                        ->label(__('sanabel.beneficiary.incomes'))
                        ->schema([
                            Forms\Components\TextInput::make('source_type')->label(__('sanabel.income.source'))->required(),
                            Forms\Components\TextInput::make('amount')
                                ->label(__('sanabel.income.amount'))
                                ->numeric()->minValue(0)->required()
                                ->suffix(config('sanabel.currency')),
                            Forms\Components\Toggle::make('is_stable')
                                ->label(__('sanabel.income.is_stable'))
                                ->helperText(__('sanabel.income.stable_help')),
                        ])->columns(3),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_housing'))->schema([
                    Forms\Components\Group::make()->relationship('housing')->schema([
                        Forms\Components\Select::make('housing_type')
                            ->label(__('sanabel.housing.type'))
                            ->options(__('sanabel.housing_type'))->required()->live(),

                        Forms\Components\TextInput::make('monthly_rent')
                            ->label(__('sanabel.housing.rent'))
                            ->numeric()->minValue(0)->default(0)
                            ->suffix(config('sanabel.currency'))
                            ->visible(fn (Forms\Get $get) => $get('housing_type') === 'rent'),

                        Forms\Components\TextInput::make('habitable_rooms')
                            ->label(__('sanabel.housing.habitable_rooms'))
                            ->helperText(__('sanabel.housing.rooms_help'))
                            ->numeric()->minValue(0)->default(1)->required(),

                        Forms\Components\Select::make('safety_band')->label(__('sanabel.housing.safety'))->options(__('sanabel.bands.safety'))->default(0)->required(),
                        Forms\Components\Select::make('services_band')->label(__('sanabel.housing.services'))->options(__('sanabel.bands.services'))->default(0)->required(),
                        Forms\Components\Select::make('eviction_band')->label(__('sanabel.housing.eviction'))->options(__('sanabel.bands.eviction'))->default(0)->required(),

                        Forms\Components\TextInput::make('landlord_name_ar')->label(__('sanabel.housing.landlord_name')),
                        Forms\Components\TextInput::make('landlord_phone_encrypted')->label(__('sanabel.housing.landlord_phone'))->tel(),
                    ])->columns(2),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_health'))->schema([
                    Forms\Components\Repeater::make('healthRecords')
                        ->relationship()
                        ->label(__('sanabel.beneficiary.health'))
                        ->schema([
                            Forms\Components\Select::make('severity_band')->label(__('sanabel.health.severity'))->options(__('sanabel.bands.severity'))->default(0)->required(),
                            Forms\Components\Select::make('economic_impact_band')->label(__('sanabel.health.economic_impact'))->options(__('sanabel.bands.economic_impact'))->default(0)->required(),
                            Forms\Components\Select::make('care_burden_band')->label(__('sanabel.health.care_burden'))->options(__('sanabel.bands.care_burden'))->default(0)->required(),
                            Forms\Components\TextInput::make('monthly_medical_cost')
                                ->label(__('sanabel.health.monthly_cost'))
                                ->helperText(__('sanabel.health.cost_help'))
                                ->numeric()->minValue(0)->default(0)
                                ->suffix(config('sanabel.currency')),
                            Forms\Components\Textarea::make('description_ar')->label(__('sanabel.health.description'))->columnSpanFull(),
                        ])->columns(2),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_urgency'))->schema([
                    Forms\Components\DateTimePicker::make('urgency_deadline_at')
                        ->label(__('sanabel.beneficiary.urgency_deadline'))
                        ->helperText(__('sanabel.beneficiary.urgency_help')),

                    Forms\Components\TextInput::make('documented_debt')
                        ->label(__('sanabel.beneficiary.documented_debt'))
                        ->helperText(__('sanabel.beneficiary.debt_help'))
                        ->numeric()->minValue(0)->default(0)
                        ->suffix(config('sanabel.currency')),

                    // Read-only. The engine computes these; the form never accepts them.
                    Forms\Components\Placeholder::make('computed_need')
                        ->label(__('sanabel.beneficiary.computed_need'))
                        ->content(fn (?Beneficiary $record) => $record?->currentAssessment()?->monthly_need ?? '—'),

                    Forms\Components\Placeholder::make('computed_score')
                        ->label(__('sanabel.beneficiary.computed_score'))
                        ->content(fn (?Beneficiary $record) => $record?->currentAssessment()?->base_score ?? '—'),

                    Forms\Components\Placeholder::make('coverage')
                        ->label(__('sanabel.beneficiary.coverage'))
                        ->content(fn (?Beneficiary $record) => $record
                            ? app(CoverageService::class)->coveragePercent($record).'%'
                            : '—'),
                ])->columns(2),
            ])->columnSpanFull()->skippable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('file_number')->label(__('sanabel.beneficiary.file_number'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('family_name')->label(__('sanabel.beneficiary.family_name'))->searchable(),
                Tables\Columns\TextColumn::make('region.name_ar')->label(__('sanabel.beneficiary.region'))->sortable(),
                Tables\Columns\TextColumn::make('support_type')
                    ->label(__('sanabel.beneficiary.support_type'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.masked.need_type.'.$state)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __('sanabel.status.'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'published', 'approved' => 'success',
                        'rejected', 'suspended' => 'danger',
                        'needs_reassessment' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('duplicate_review_flag')
                    ->label(__('sanabel.beneficiary.duplicate_flag'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('next_assessment_due_at')
                    ->label(__('sanabel.beneficiary.next_assessment'))
                    ->date()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(collect(Beneficiary::STATUSES)->mapWithKeys(fn ($s) => [$s => __('sanabel.status.'.$s)])),
                Tables\Filters\SelectFilter::make('region_id')
                    ->label(__('sanabel.beneficiary.region'))
                    ->options(fn () => Region::pluck('name_ar', 'id')),
                Tables\Filters\TernaryFilter::make('duplicate_review_flag')
                    ->label(__('sanabel.beneficiary.duplicate_flag')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /** dependent and unable_to_earn are always derived, never taken from the form. */
    private static function deriveMemberFlags(array $data): array
    {
        $age = (int) date('Y') - (int) ($data['birth_year'] ?? date('Y'));

        $data['person_class'] = DependencyRules::personClass($age);
        $data['dependent'] = DependencyRules::isDependent($age, (bool) ($data['is_student'] ?? false), false);
        $data['unable_to_earn'] = DependencyRules::isUnableToEarn((bool) ($data['has_documented_condition'] ?? false));

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBeneficiaries::route('/'),
            'create' => Pages\CreateBeneficiary::route('/create'),
            'view' => Pages\ViewBeneficiary::route('/{record}'),
            'edit' => Pages\EditBeneficiary::route('/{record}/edit'),
        ];
    }
}
