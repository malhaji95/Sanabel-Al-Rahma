<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BeneficiaryResource\Pages;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Models\Region;
use App\Models\User;
use App\Services\CoverageService;
use App\Services\DependencyRules;
use App\Services\IdentityFormat;
use App\Services\RankingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

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

                    // T-50 — the number this family carried in the old system.
                    // Unique, so the same old file cannot be imported twice.
                    Forms\Components\TextInput::make('legacy_file_no')
                        ->label(__('sanabel.beneficiary.legacy_file_no'))
                        ->helperText(__('sanabel.beneficiary.legacy_file_no_help'))
                        ->maxLength(64)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('national_id_encrypted')
                        ->label(__('sanabel.beneficiary.national_id'))
                        ->required()
                        // T-121 — «11 الرقم يتوقف, يعني لا يقبل اكثر او اقل».
                        // The box stops at eleven and the rule refuses ten.
                        ->maxLength(IdentityFormat::NATIONAL_ID_DIGITS)
                        ->rule(fn () => IdentityFormat::lengthRule(
                            IdentityFormat::NATIONAL_ID_DIGITS,
                            __('sanabel.cases.national_id_digits'),
                        ))
                        ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state))
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

                    Forms\Components\Select::make('gender')
                        ->label(__('sanabel.beneficiary.gender'))
                        ->options(__('sanabel.gender'))
                        // T-121 — the marital states below are written to match.
                        ->live(),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label(__('sanabel.beneficiary.birth_date'))
                        ->maxDate(now()),

                    Forms\Components\TextInput::make('phone_encrypted')
                        ->label(__('sanabel.beneficiary.phone'))
                        ->tel()
                        ->maxLength(20)
                        ->rule(fn () => IdentityFormat::lengthRule(
                            IdentityFormat::PHONE_DIGITS,
                            __('sanabel.cases.phone_digits'),
                        ))
                        ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),
                    // T-59 — on a live file this is read-only, and the change
                    // goes through the header action beside it so that
                    // somebody other than whoever asked for it approves it.
                    Forms\Components\TextInput::make('wallet_encrypted')
                        ->label(__('sanabel.beneficiary.wallet'))
                        ->helperText(fn (?Beneficiary $record) => self::paymentDetailsLocked($record)
                            ? __('sanabel.cases.wallet_locked_help')
                            : __('sanabel.beneficiary.wallet_optional'))
                        ->disabled(fn (?Beneficiary $record) => self::paymentDetailsLocked($record))
                        ->dehydrated(fn (?Beneficiary $record) => ! self::paymentDetailsLocked($record)),

                    Forms\Components\Select::make('association_id')
                        ->label(__('sanabel.beneficiary.association'))
                        ->options(fn () => User::query()
                            ->whereHas('role', fn ($q) => $q->where('key', 'association'))
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->helperText(__('sanabel.beneficiary.association_help')),

                    /*
                     | T-121 — «عند ادخال المنطقة موجود القرى كذالك, نحتاج فقط
                     | أظهار المنطقة والقرى موجود لوحدها», 25 October.
                     |
                     | One list held all hundred and forty-seven places at
                     | once: the governorate, the five areas and every village,
                     | town and city under them. Two now, and the first only
                     | narrows the second — what is saved is still the locality
                     | itself, because that is what a delegate is attached to
                     | and what the reference amounts are resolved from.
                     */
                    Forms\Components\Select::make('region_area_id')
                        ->label(__('sanabel.beneficiary.region_area'))
                        ->options(fn () => Region::where('is_active', true)
                            ->where('type', 'area')
                            ->orderBy('name_ar')
                            ->pluck('name_ar', 'id'))
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        /*
                         | On an open file the area is read back rather than
                         | asked for again — from the locality's parent, or
                         | from the region itself on a file that was attached
                         | to an area before the two questions were split.
                         */
                        ->afterStateHydrated(function (Forms\Components\Select $component, ?Beneficiary $record) {
                            $region = $record?->region;

                            $component->state($region?->type === 'area' ? $region->id : $region?->parent_id);
                        })
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('region_id', null)),

                    Forms\Components\Select::make('region_id')
                        ->label(__('sanabel.beneficiary.region'))
                        ->helperText(__('sanabel.beneficiary.region_help'))
                        ->options(fn (Forms\Get $get) => Region::where('is_active', true)
                            ->where('parent_id', $get('region_area_id'))
                            ->orderBy('name_ar')
                            ->pluck('name_ar', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\TextInput::make('town_ar')
                        ->label(__('sanabel.beneficiary.town_ar')),

                    Forms\Components\TextInput::make('quarter_ar')
                        ->label(__('sanabel.beneficiary.quarter_ar')),

                    /*
                     | T-121 — «موقع السكن خط العرض وخط الطول مو واضح معنا وما
                     | بيدخل, مافهمناها», 25 October.
                     |
                     | Two decimal degrees, typed by hand, on a phone whose
                     | keyboard sends Arabic-Indic digits. Nobody was going to
                     | fill that in, and nobody should have been asked to: the
                     | delegate's own visit screen has had a «حدّد الموقع»
                     | button since T-14 and they know it. This is that button,
                     | and the two numbers are what it writes rather than what
                     | anyone types.
                     */
                    Forms\Components\ViewField::make('location_capture')
                        ->view('filament.forms.location')
                        ->dehydrated(false)
                        ->columnSpanFull(),

                    Forms\Components\Hidden::make('latitude'),
                    Forms\Components\Hidden::make('longitude'),

                    /*
                     | T-121 — «عند ادخال أنثى يظهر بيانات انه مطلق وهذا للرجل
                     | … عند ادخل أنثى يجب تأنيثهم», 25 October.
                     |
                     | The stored value does not change — `widowed` is
                     | `widowed` — only the word the researcher reads. The
                     | feminine list has existed since T-101 for the donor's
                     | card; it belongs on the form that a widow is sitting in
                     | front of far more than it belongs there.
                     */
                    Forms\Components\Select::make('marital_status')
                        ->label(__('sanabel.beneficiary.marital_status'))
                        ->options(fn (Forms\Get $get) => self::maritalOptions($get('gender')))
                        ->live()
                        ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                            if ($value && $value !== 'single'
                                && ! DependencyRules::answersAsAdult(self::ageFromDate($get('birth_date')))) {
                                $fail(__('sanabel.cases.too_young_to_marry'));
                            }
                        }),

                    // هل يعمل صاحب العلاقة — and where, only if he does.
                    Forms\Components\Toggle::make('works')
                        ->label(__('sanabel.beneficiary.works'))
                        ->live()
                        ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                            if ($value && ! DependencyRules::answersAsAdult(self::ageFromDate($get('birth_date')))) {
                                $fail(__('sanabel.cases.too_young_to_work'));
                            }
                        }),

                    Forms\Components\TextInput::make('workplace_ar')
                        ->label(__('sanabel.beneficiary.workplace_ar'))
                        ->visible(fn (Forms\Get $get) => (bool) $get('works')),

                    // T-121 — what the work pays, asked where the work is.
                    Forms\Components\TextInput::make('work_income')
                        ->label(__('sanabel.beneficiary.work_income'))
                        ->helperText(__('sanabel.beneficiary.work_income_help'))
                        ->numeric()->minValue(0)
                        ->visible(fn (Forms\Get $get) => (bool) $get('works')),

                    /*
                     | T-100 — the survey asks it of the head and of every
                     | dependant. It is a question about expenditure, not about
                     | character, and the helper text says so: an assessor who
                     | reads it as a judgement will not ask it honestly.
                     */
                    // T-121 — «الملاحظة إلى تحت المدخن حذفها», 25 October.
                    Forms\Components\Toggle::make('smokes')
                        ->label(__('sanabel.beneficiary.smokes')),

                    Forms\Components\Toggle::make('has_quran_memorizers')
                        ->label(__('sanabel.beneficiary.has_quran_memorizers'))
                        ->live(),

                    Forms\Components\TextInput::make('quran_memorizer_count')
                        ->label(__('sanabel.beneficiary.quran_memorizer_count'))
                        ->numeric()->minValue(0)
                        ->visible(fn (Forms\Get $get) => (bool) $get('has_quran_memorizers')),

                    Forms\Components\Select::make('support_type')
                        ->label(__('sanabel.beneficiary.support_type'))
                        ->options(__('sanabel.masked.need_type'))
                        ->required(),

                    /*
                     | T-73 — the researcher's view and the management's
                     | decision, side by side and not the same field. Their
                     | survey asks the researcher whether the family is
                     | entitled to zakat; their answer of 13 October is that
                     | the channel is the management's own call.
                     */
                    Forms\Components\Toggle::make('zakat_recommended')
                        ->label(__('sanabel.zakat.recommended'))
                        ->helperText(__('sanabel.zakat.recommended_help')),

                    // One of the eight, or nothing. The donor is told only that
                    // zakat may be paid on the file, never which category.
                    Forms\Components\Select::make('zakat_category')
                        ->label(__('sanabel.zakat.category'))
                        ->helperText(fn () => auth()->user()?->can_('approve_case')
                            ? __('sanabel.zakat.category_help')
                            : __('sanabel.zakat.category_help_management'))
                        // The model refuses the write in any case; the screen
                        // says so before the clerk types rather than after.
                        ->disabled(fn () => ! auth()->user()?->can_('approve_case'))
                        ->dehydrated(fn () => (bool) auth()->user()?->can_('approve_case'))
                        ->hint(fn (?Beneficiary $record) => match ($record?->zakat_recommended) {
                            true => __('sanabel.zakat.recommended_yes'),
                            false => __('sanabel.zakat.recommended_no'),
                            default => __('sanabel.zakat.recommended_none'),
                        })
                        ->options(collect(Beneficiary::ZAKAT_CATEGORIES)
                            ->mapWithKeys(fn (string $k) => [$k => __('sanabel.zakat.categories.'.$k)])
                            ->all()),

                    // T-63 — their survey's own classification. For filtering,
                    // and deliberately not the zakat channel beside it.
                    // T-69 — a list, not one value: the filled dossier answers
                    // «فقيرة، يتيمة».
                    Forms\Components\Select::make('case_classifications')
                        ->label(__('sanabel.beneficiary.case_classification'))
                        ->helperText(__('sanabel.beneficiary.case_classification_help'))
                        ->multiple()
                        ->options(collect(Beneficiary::CASE_CLASSIFICATIONS)
                            ->mapWithKeys(fn (string $k) => [$k => __('sanabel.case_classification.'.$k)])
                            ->all()),

                    Forms\Components\Toggle::make('is_shareable')
                        ->label(__('sanabel.beneficiary.is_shareable'))
                        ->helperText(__('sanabel.beneficiary.is_shareable_help'))
                        ->default(true),

                    // Recorded, never computed from: the need engine does not
                    // read it, so a note here cannot move a score.
                    Forms\Components\Textarea::make('previous_aid_ar')
                        ->label(__('sanabel.beneficiary.previous_aid'))
                        ->helperText(__('sanabel.beneficiary.previous_aid_help'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_members'))->schema([
                    Forms\Components\Repeater::make('members')
                        // $hidden keeps the identity number out of
                        // attributesToArray(), which is the array Filament
                        // fills a repeater row from, so it reached the screen
                        // empty and saving wrote the blank back over it. Named
                        // here and nowhere else, so nothing is unhidden that
                        // the screen does not show.
                        ->relationship(modifyQueryUsing: fn ($query) => self::unhide(
                            $query,
                            ['national_id_encrypted', 'spouse_national_id_encrypted'],
                        ))
                        ->label(__('sanabel.beneficiary.members'))
                        ->schema([
                            Forms\Components\TextInput::make('name_ar')->label(__('sanabel.household_member.name'))->required(),

                            // Somebody else's identity number is still an
                            // identity number: encrypted at rest like the head
                            // of household's.
                            Forms\Components\TextInput::make('national_id_encrypted')
                                ->label(__('sanabel.household_member.national_id'))
                                ->maxLength(IdentityFormat::NATIONAL_ID_DIGITS)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::NATIONAL_ID_DIGITS,
                                    __('sanabel.cases.national_id_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),

                            Forms\Components\Select::make('relation')
                                ->label(__('sanabel.household_member.relation'))
                                ->options(__('sanabel.relation'))
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => $set(
                                    'person_class',
                                    DependencyRules::personClass(
                                        (int) date('Y') - (int) ($get('birth_year') ?: date('Y')),
                                        $state,
                                    ),
                                )),
                            Forms\Components\TextInput::make('relation_other_ar')
                                ->label(__('sanabel.household_member.relation_other_ar'))
                                ->visible(fn (Forms\Get $get) => $get('relation') === 'other'),

                            // The survey gives a full date; the engine reads the
                            // year. Filling the date fills the year from it, so
                            // the two can never say different things.
                            Forms\Components\DatePicker::make('birth_date')
                                ->label(__('sanabel.household_member.birth_date'))
                                ->helperText(__('sanabel.household_member.birth_date_help'))
                                ->maxDate(now())
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    if (! $state) {
                                        return;
                                    }

                                    $year = (int) Carbon::parse($state)->year;
                                    $set('birth_year', $year);
                                    $set('person_class', DependencyRules::personClass(
                                        (int) date('Y') - $year,
                                        $get('relation'),
                                    ));
                                }),

                            Forms\Components\TextInput::make('birth_year')
                                ->label(__('sanabel.household_member.birth_year'))
                                ->numeric()->minValue(1900)->maxValue((int) date('Y'))
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    if ($state) {
                                        $set('person_class', DependencyRules::personClass(
                                            (int) date('Y') - (int) $state,
                                            $get('relation'),
                                        ));
                                    }
                                }),
                            Forms\Components\Select::make('gender')
                                ->label(__('sanabel.household_member.gender'))
                                ->options(__('sanabel.gender'))->required()
                                // T-121 — the marital states below follow it.
                                ->live(),

                            // Derived, never typed: each member falls under exactly one class.
                            Forms\Components\Select::make('person_class')
                                ->label(__('sanabel.household_member.person_class'))
                                ->options(__('sanabel.person_class'))
                                ->required()
                                ->disabled()
                                ->dehydrated(),

                            Forms\Components\Select::make('marital_status')
                                ->label(__('sanabel.household_member.marital_status'))
                                ->options(fn (Forms\Get $get) => self::maritalOptions($get('gender')))
                                ->live()
                                ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                                    if ($value && $value !== 'single'
                                        && ! DependencyRules::answersAsAdult(self::memberAge($get))) {
                                        $fail(__('sanabel.cases.too_young_to_marry'));
                                    }
                                }),

                            Forms\Components\TextInput::make('spouse_name_ar')
                                ->label(__('sanabel.household_member.spouse_name_ar'))
                                ->visible(fn (Forms\Get $get) => $get('marital_status') === 'married'),

                            Forms\Components\TextInput::make('spouse_national_id_encrypted')
                                ->label(__('sanabel.household_member.spouse_national_id'))
                                ->maxLength(IdentityFormat::NATIONAL_ID_DIGITS)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::NATIONAL_ID_DIGITS,
                                    __('sanabel.cases.national_id_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state))
                                ->visible(fn (Forms\Get $get) => $get('marital_status') === 'married'),

                            // T-69 — «تصنيف اليتم», asked of each dependant.
                            Forms\Components\Toggle::make('is_orphan')
                                ->label(__('sanabel.household_member.is_orphan')),

                            Forms\Components\Select::make('occupation')
                                ->label(__('sanabel.household_member.occupation'))
                                ->options(__('sanabel.occupation')),

                            Forms\Components\Toggle::make('is_student')->label(__('sanabel.household_member.is_student'))->live(),

                            Forms\Components\TextInput::make('school_grade_ar')
                                ->label(__('sanabel.household_member.school_grade_ar'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('is_student')),

                            Forms\Components\Toggle::make('works')
                                ->label(__('sanabel.household_member.works'))
                                ->live()
                                ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                                    if ($value && ! DependencyRules::answersAsAdult(self::memberAge($get))) {
                                        $fail(__('sanabel.cases.too_young_to_work'));
                                    }
                                }),

                            Forms\Components\TextInput::make('workplace_ar')
                                ->label(__('sanabel.household_member.workplace_ar'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('works')),

                            // T-121 — what this one's work pays.
                            Forms\Components\TextInput::make('work_income')
                                ->label(__('sanabel.household_member.work_income'))
                                ->numeric()->minValue(0)
                                ->visible(fn (Forms\Get $get) => (bool) $get('works')),

                            Forms\Components\Toggle::make('smokes')
                                ->label(__('sanabel.household_member.smokes')),

                            Forms\Components\Toggle::make('is_quran_memorizer')
                                ->label(__('sanabel.household_member.is_quran_memorizer')),

                            Forms\Components\TextInput::make('certificate_ar')
                                ->label(__('sanabel.household_member.certificate_ar')),

                            Forms\Components\TextInput::make('skills_ar')
                                ->label(__('sanabel.household_member.skills_ar')),

                            Forms\Components\Toggle::make('has_documented_condition')
                                ->label(__('sanabel.household_member.has_documented_condition'))
                                ->helperText(__('sanabel.household_member.condition_help'))
                                ->live(),

                            // Both flags are computed from the rules, not entered by hand.
                            Forms\Components\Placeholder::make('dependent_preview')
                                ->label(__('sanabel.household_member.dependent'))
                                ->content(fn (Forms\Get $get) => DependencyRules::isDependent(
                                    (int) date('Y') - (int) ($get('birth_year') ?: date('Y')),
                                    (bool) $get('is_student'),
                                    false,
                                ) ? __('sanabel.yes') : __('sanabel.no')),

                            Forms\Components\Placeholder::make('unable_preview')
                                ->label(__('sanabel.household_member.unable_to_earn'))
                                ->content(fn (Forms\Get $get) => DependencyRules::isUnableToEarn(
                                    (bool) $get('has_documented_condition')
                                ) ? __('sanabel.yes') : __('sanabel.no')),

                            Forms\Components\Textarea::make('notes_ar')->label(__('sanabel.household_member.notes'))->columnSpanFull(),
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
                                ->helperText(__('sanabel.income.stable_help'))
                                ->live(),

                            // Approved 24 Sep 2026. Asked for only when the
                            // income is unstable, because a stable income has
                            // no recurrence to record. It is documentation:
                            // an unstable income is never deducted from the
                            // need, whatever it says.
                            Forms\Components\Select::make('recurrence')
                                ->label(__('sanabel.income.recurrence'))
                                ->helperText(__('sanabel.income.recurrence_help'))
                                ->options(__('sanabel.income_recurrence'))
                                ->visible(fn (Forms\Get $get) => ! $get('is_stable'))
                                ->required(fn (Forms\Get $get) => ! $get('is_stable'))
                                ->columnSpanFull(),
                        ])->columns(3),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_housing'))->schema([
                    Forms\Components\Group::make()->relationship('housing')->schema([
                        Forms\Components\Select::make('housing_type')
                            ->label(__('sanabel.housing.type'))
                            ->options(__('sanabel.housing_type'))->required()->live(),

                        // Free text beside the list, not a new housing type:
                        // the engine reads housing_type (shelter raises an
                        // adjustment, rent raises the rent reference), so a new
                        // option there would quietly change a need.
                        Forms\Components\TextInput::make('type_other_ar')
                            ->label(__('sanabel.housing.type_other_ar'))
                            ->helperText(__('sanabel.housing.type_other_help')),

                        Forms\Components\TextInput::make('monthly_rent')
                            ->label(__('sanabel.housing.rent'))
                            ->numeric()->minValue(0)->default(0)
                            ->suffix(config('sanabel.currency'))
                            ->visible(fn (Forms\Get $get) => $get('housing_type') === 'rent'),

                        Forms\Components\Select::make('rent_period')
                            ->label(__('sanabel.housing.rent_period'))
                            ->options(__('sanabel.rent_period'))
                            ->visible(fn (Forms\Get $get) => $get('housing_type') === 'rent'),

                        Forms\Components\TextInput::make('habitable_rooms')
                            ->label(__('sanabel.housing.habitable_rooms'))
                            ->helperText(__('sanabel.housing.rooms_help'))
                            ->numeric()->minValue(0)->default(1)->required(),

                        Forms\Components\TextInput::make('bathrooms')
                            ->label(__('sanabel.housing.bathrooms'))
                            ->numeric()->minValue(0),

                        Forms\Components\Toggle::make('has_kitchen')
                            ->label(__('sanabel.housing.has_kitchen')),

                        Forms\Components\Select::make('safety_band')->label(__('sanabel.housing.safety'))->options(__('sanabel.bands.safety'))->default(0)->required(),
                        Forms\Components\Select::make('services_band')->label(__('sanabel.housing.services'))->options(__('sanabel.bands.services'))->default(0)->required(),
                        Forms\Components\Select::make('eviction_band')->label(__('sanabel.housing.eviction'))->options(__('sanabel.bands.eviction'))->default(0)->required(),

                        Forms\Components\TextInput::make('landlord_name_ar')->label(__('sanabel.housing.landlord_name')),
                        Forms\Components\TextInput::make('landlord_phone_encrypted')
                            ->label(__('sanabel.housing.landlord_phone'))
                            ->tel()
                            ->maxLength(20)
                            ->rule(fn () => IdentityFormat::lengthRule(
                                IdentityFormat::PHONE_DIGITS,
                                __('sanabel.cases.phone_digits'),
                            ))
                            ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),
                    ])
                        ->columns(2)
                        // A Group has no query to hook, so the hidden columns
                        // are read off the row itself. Same reason as unhide().
                        ->mutateRelationshipDataBeforeFillUsing(function (array $data, Forms\Components\Group $component) {
                            $housing = $component->getCachedExistingRecord();

                            foreach (['landlord_name_ar', 'landlord_phone_encrypted'] as $column) {
                                $data[$column] = $housing?->{$column};
                            }

                            return $data;
                        }),

                    // The survey asks it under housing; the column is on the
                    // file, so it sits outside the housing group.
                    Forms\Components\Textarea::make('furnishing_ar')
                        ->label(__('sanabel.housing.furnishing_ar'))
                        ->rows(2)
                        ->columnSpanFull(),

                    /*
                     | T-100 — «حالة الفقير», in the assessor's own words.
                     |
                     | Every other column on this file is a band, a count or an
                     | amount, which is what makes the need computable and the
                     | ranking defensible. This one is read by nobody and moves
                     | nothing — deliberately, so that no family can be moved up
                     | the list by a better paragraph. It is there for the
                     | person who opens the file after the visit.
                     */
                    Forms\Components\Textarea::make('situation_ar')
                        ->label(__('sanabel.beneficiary.situation_ar'))
                        ->helperText(__('sanabel.beneficiary.situation_help'))
                        ->rows(4)
                        ->maxLength(2000)
                        ->columnSpanFull(),

                    /*
                     | T-106 — the other paragraph, and the only free text on
                     | this file a stranger ever reads. Beside the internal
                     | one on purpose: whoever is writing both should see at a
                     | glance which of the two is which.
                     |
                     | It does not reach a donor until an account that may
                     | approve a file signs it off, and every edit here takes
                     | that signature away again.
                     */
                    Forms\Components\Textarea::make('story_ar')
                        ->label(__('sanabel.beneficiary.story_ar'))
                        ->helperText(__('sanabel.beneficiary.story_help'))
                        ->rows(5)
                        ->maxLength(1500)
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('story_state')
                        ->label(__('sanabel.beneficiary.story_state'))
                        ->content(fn (?Beneficiary $record) => $record?->story_approved_at
                            ? __('sanabel.beneficiary.story_approved_on', [
                                'name' => $record->storyApprover?->name ?? '—',
                                'date' => $record->story_approved_at->translatedFormat('Y-m-d'),
                            ])
                            : __('sanabel.beneficiary.story_not_approved'))
                        ->visible(fn (?Beneficiary $record) => filled($record?->story_ar))
                        ->columnSpanFull(),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_health'))->schema([
                    Forms\Components\Repeater::make('healthRecords')
                        ->relationship(modifyQueryUsing: fn ($query) => self::unhide(
                            $query,
                            ['description_ar', 'doctor_phone_encrypted'],
                        ))
                        ->label(__('sanabel.beneficiary.health'))
                        ->schema([
                            // T-101 — المرض من قائمة، وهو الحقل الصحي الوحيد
                            // الذي يراه المتبرّع. التفاصيل في «الوصف» أدناه،
                            // وهو داخلي ولا يُنشر.
                            Forms\Components\Select::make('condition')
                                ->label(__('sanabel.health.condition'))
                                ->helperText(__('sanabel.health.condition_help'))
                                ->options(__('sanabel.health.conditions'))
                                ->searchable(),
                            Forms\Components\Select::make('severity_band')->label(__('sanabel.health.severity'))->options(__('sanabel.bands.severity'))->default(0)->required(),
                            Forms\Components\Select::make('economic_impact_band')->label(__('sanabel.health.economic_impact'))->options(__('sanabel.bands.economic_impact'))->default(0)->required(),
                            Forms\Components\Select::make('care_burden_band')->label(__('sanabel.health.care_burden'))->options(__('sanabel.bands.care_burden'))->default(0)->required(),
                            Forms\Components\TextInput::make('monthly_medical_cost')
                                ->label(__('sanabel.health.monthly_cost'))
                                ->helperText(__('sanabel.health.cost_help'))
                                ->numeric()->minValue(0)->default(0)
                                ->suffix(config('sanabel.currency')),
                            Forms\Components\Toggle::make('ongoing_treatment')
                                ->label(__('sanabel.health.ongoing_treatment'))
                                ->live(),

                            Forms\Components\TextInput::make('treatment_place_ar')
                                ->label(__('sanabel.health.treatment_place_ar'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('ongoing_treatment')),

                            Forms\Components\TextInput::make('doctor_name_ar')
                                ->label(__('sanabel.health.doctor_name_ar')),

                            // A doctor's number is a telephone number too.
                            Forms\Components\TextInput::make('doctor_phone_encrypted')
                                ->label(__('sanabel.health.doctor_phone'))
                                ->tel()
                                ->maxLength(20)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::PHONE_DIGITS,
                                    __('sanabel.cases.phone_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),

                            Forms\Components\Textarea::make('medicines_ar')
                                ->label(__('sanabel.health.medicines_ar'))
                                ->rows(2)
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('description_ar')->label(__('sanabel.health.description'))->columnSpanFull(),
                        ])->columns(2),
                ]),

                // T-55 — what the family owns and what they owe. Each row is
                // the record of what was said; the two questions the
                // association answered on 11 October are asked here, and
                // nothing is inferred from a row without them.
                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_assets'))->schema([
                    Forms\Components\Repeater::make('assets')
                        ->relationship()
                        ->label(__('sanabel.asset.plural'))
                        ->schema([
                            Forms\Components\Select::make('kind')
                                ->label(__('sanabel.asset.kind'))
                                ->options(__('sanabel.asset.kinds'))
                                ->required()
                                ->live(),

                            Forms\Components\TextInput::make('kind_other_ar')
                                ->label(__('sanabel.asset.kind_other_ar'))
                                ->visible(fn (Forms\Get $get) => $get('kind') === 'other'),

                            // T-69 — the dossier asks after the spouse's own
                            // property, and a committee weighing what a family
                            // controls needs to know whose it is.
                            Forms\Components\Select::make('belongs_to')
                                ->label(__('sanabel.asset.belongs_to'))
                                ->options(__('sanabel.asset.belongs_to_options')),

                            // Asked of the delegate, never inferred from the
                            // kind: the same tractor is a livelihood to one
                            // family and an investment to another.
                            Forms\Components\Select::make('necessity')
                                ->label(__('sanabel.asset.necessity'))
                                ->helperText(__('sanabel.asset.necessity_help'))
                                ->options(__('sanabel.asset.necessities'))
                                ->live(),

                            Forms\Components\TextInput::make('necessity_reason_ar')
                                ->label(__('sanabel.asset.necessity_reason_ar'))
                                ->visible(fn (Forms\Get $get) => $get('necessity') === 'essential'),

                            Forms\Components\TextInput::make('estimated_value')
                                ->label(__('sanabel.asset.estimated_value'))
                                ->numeric()->minValue(0)
                                ->suffix(config('sanabel.currency')),

                            // T-64 — the two questions a number could not
                            // answer, asked of the researcher on the visit.
                            Forms\Components\Toggle::make('is_exploited')
                                ->label(__('sanabel.asset.is_exploited'))
                                ->helperText(__('sanabel.asset.is_exploited_help')),

                            Forms\Components\Toggle::make('has_control')
                                ->label(__('sanabel.asset.has_control'))
                                ->helperText(__('sanabel.asset.has_control_help')),

                            // Required: the committee judges by hand, so its
                            // only material is what the researcher wrote.
                            Forms\Components\Textarea::make('details_ar')
                                ->label(__('sanabel.asset.details_ar'))
                                ->helperText(__('sanabel.asset.details_required_help'))
                                ->required()
                                ->rows(3)
                                ->columnSpanFull(),
                        ])->columns(2),

                    // Read-only, and compared to nothing: the association
                    // decided on 12 October that the committee judges by hand,
                    // so this is here to save them adding up a list.
                    Forms\Components\Placeholder::make('surplus_asset_total')
                        ->label(__('sanabel.asset.surplus_total'))
                        ->helperText(__('sanabel.asset.surplus_total_help'))
                        ->content(fn (?Beneficiary $record) => $record
                            ? number_format(app(RankingService::class)->surplusAssetValue($record))
                                .' '.config('sanabel.currency')
                            : '—'),

                    Forms\Components\Repeater::make('livestock')
                        ->relationship()
                        ->label(__('sanabel.livestock.plural'))
                        ->schema([
                            Forms\Components\Select::make('kind')
                                ->label(__('sanabel.livestock.kind'))
                                ->options(__('sanabel.livestock.kinds'))
                                ->required()
                                ->live(),

                            Forms\Components\TextInput::make('kind_other_ar')
                                ->label(__('sanabel.livestock.kind_other_ar'))
                                ->visible(fn (Forms\Get $get) => $get('kind') === 'other'),

                            Forms\Components\TextInput::make('head_count')
                                ->label(__('sanabel.livestock.head_count'))
                                ->numeric()->minValue(0)->default(0),

                            Forms\Components\Select::make('income_kind')
                                ->label(__('sanabel.livestock.income_kind'))
                                ->options(__('sanabel.livestock.income_kinds'))
                                ->live(),

                            Forms\Components\TextInput::make('monthly_income')
                                ->label(__('sanabel.livestock.monthly_income'))
                                ->helperText(__('sanabel.livestock.monthly_income_help'))
                                ->numeric()->minValue(0)
                                ->suffix(config('sanabel.currency'))
                                ->visible(fn (Forms\Get $get) => in_array(
                                    $get('income_kind'),
                                    ['partial_income', 'main_income'],
                                    true,
                                )),

                            // The one answer the engine reads. Asked only when
                            // there is an income to ask about.
                            Forms\Components\Toggle::make('is_stable')
                                ->label(__('sanabel.livestock.is_stable'))
                                ->helperText(__('sanabel.livestock.is_stable_help'))
                                ->visible(fn (Forms\Get $get) => in_array(
                                    $get('income_kind'),
                                    ['partial_income', 'main_income'],
                                    true,
                                )),
                        ])->columns(2),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_obligations'))->schema([
                    Forms\Components\Repeater::make('debts')
                        ->relationship(modifyQueryUsing: fn ($query) => self::unhide(
                            $query,
                            ['creditor_phone_encrypted'],
                        ))
                        ->label(__('sanabel.debt.plural'))
                        ->schema([
                            Forms\Components\TextInput::make('creditor_name_ar')
                                ->label(__('sanabel.debt.creditor_name_ar'))
                                ->required(),

                            Forms\Components\TextInput::make('amount')
                                ->label(__('sanabel.debt.amount'))
                                ->helperText(__('sanabel.debt.engine_help'))
                                ->numeric()->minValue(0)->required()
                                ->suffix(config('sanabel.currency')),

                            Forms\Components\TextInput::make('creditor_address_ar')
                                ->label(__('sanabel.debt.creditor_address_ar')),

                            Forms\Components\TextInput::make('creditor_phone_encrypted')
                                ->label(__('sanabel.debt.creditor_phone'))
                                ->tel()
                                ->maxLength(20)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::PHONE_DIGITS,
                                    __('sanabel.cases.phone_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),

                            Forms\Components\TextInput::make('months')
                                ->label(__('sanabel.debt.months'))
                                ->numeric()->minValue(0),

                            Forms\Components\Toggle::make('has_due_date')
                                ->label(__('sanabel.debt.has_due_date'))
                                ->live(),

                            Forms\Components\DatePicker::make('due_date')
                                ->label(__('sanabel.debt.due_date'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('has_due_date')),

                            Forms\Components\TextInput::make('nature_ar')
                                ->label(__('sanabel.debt.nature_ar')),

                            Forms\Components\Textarea::make('reason_ar')
                                ->label(__('sanabel.debt.reason_ar'))
                                ->rows(2)
                                ->columnSpanFull(),
                        ])->columns(2),

                    Forms\Components\Repeater::make('supportSources')
                        ->relationship()
                        ->label(__('sanabel.support_source.plural'))
                        ->schema([
                            Forms\Components\TextInput::make('name_ar')
                                ->label(__('sanabel.support_source.name_ar'))
                                ->required(),

                            Forms\Components\Select::make('kind')
                                ->label(__('sanabel.support_source.kind'))
                                ->helperText(__('sanabel.support_source.one_off_help'))
                                ->options(__('sanabel.support_source.kinds')),

                            Forms\Components\TextInput::make('amount')
                                ->label(__('sanabel.support_source.amount'))
                                ->numeric()->minValue(0)
                                ->suffix(config('sanabel.currency')),

                            // The month this payment answers, and only this
                            // month: it comes off that month's remaining need
                            // the way a partial donation does.
                            Forms\Components\DatePicker::make('coverage_month')
                                ->label(__('sanabel.support_source.coverage_month'))
                                ->helperText(__('sanabel.support_source.coverage_month_help'))
                                ->displayFormat('Y-m')
                                ->format('Y-m-01'),

                            Forms\Components\DatePicker::make('paid_on')
                                ->label(__('sanabel.support_source.paid_on')),

                            Forms\Components\Textarea::make('notes_ar')
                                ->label(__('sanabel.support_source.notes_ar'))
                                ->rows(2)
                                ->columnSpanFull(),
                        ])->columns(2),

                    Forms\Components\Repeater::make('caregivers')
                        ->relationship(modifyQueryUsing: fn ($query) => self::unhide(
                            $query,
                            ['national_id_encrypted'],
                        ))
                        ->label(__('sanabel.caregiver.plural'))
                        ->schema([
                            Forms\Components\TextInput::make('name_ar')
                                ->label(__('sanabel.caregiver.name_ar')),

                            Forms\Components\Select::make('relation')
                                ->label(__('sanabel.caregiver.relation'))
                                ->options(__('sanabel.caregiver.relations'))
                                ->live(),

                            Forms\Components\TextInput::make('relation_other_ar')
                                ->label(__('sanabel.caregiver.relation_other_ar'))
                                ->visible(fn (Forms\Get $get) => $get('relation') === 'other'),

                            Forms\Components\TextInput::make('national_id_encrypted')
                                ->label(__('sanabel.caregiver.national_id'))
                                ->maxLength(64),

                            Forms\Components\TextInput::make('income_source_ar')
                                ->label(__('sanabel.caregiver.income_source_ar')),

                            Forms\Components\TextInput::make('monthly_support')
                                ->label(__('sanabel.caregiver.monthly_support'))
                                ->numeric()->minValue(0)
                                ->suffix(config('sanabel.currency')),

                            Forms\Components\Toggle::make('smokes')
                                ->label(__('sanabel.caregiver.smokes')),

                            Forms\Components\Toggle::make('neighbours_help')
                                ->label(__('sanabel.caregiver.neighbours_help'))
                                ->live(),

                            Forms\Components\TextInput::make('neighbours_support')
                                ->label(__('sanabel.caregiver.neighbours_support'))
                                ->numeric()->minValue(0)
                                ->suffix(config('sanabel.currency'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('neighbours_help')),

                            Forms\Components\TextInput::make('neighbours_relation')
                                ->label(__('sanabel.caregiver.neighbours_relation'))
                                ->visible(fn (Forms\Get $get) => (bool) $get('neighbours_help')),

                            Forms\Components\Textarea::make('notes_ar')
                                ->label(__('sanabel.caregiver.notes_ar'))
                                ->rows(2)
                                ->columnSpanFull(),
                        ])->columns(2),
                ]),

                // What the family themselves propose, and who took the survey.
                // Recorded for the committee; the engine reads none of it.
                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_proposal'))->schema([
                    Forms\Components\Textarea::make('livelihood_proposal_ar')
                        ->label(__('sanabel.beneficiary.livelihood_proposal_ar'))
                        ->helperText(__('sanabel.beneficiary.proposal_help'))
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('proposal_skills_ar')
                        ->label(__('sanabel.beneficiary.proposal_skills_ar'))
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('proposal_cost')
                        ->label(__('sanabel.beneficiary.proposal_cost'))
                        ->numeric()->minValue(0)
                        ->suffix(config('sanabel.currency')),

                    Forms\Components\TextInput::make('surveyed_by_ar')
                        ->label(__('sanabel.beneficiary.surveyed_by_ar')),

                    /*
                     | T-63 — what the old system's researcher wrote. An
                     | archive to look back at: the file itself re-entered as a
                     | draft and went through this system's own chain, so this
                     | is shown and never edited, and nothing reads it.
                     */
                    Forms\Components\Placeholder::make('legacy_review')
                        ->label(__('sanabel.beneficiary.legacy_review'))
                        ->helperText(__('sanabel.beneficiary.legacy_review_help'))
                        ->visible(fn (?Beneficiary $record) => filled($record?->legacy_review_json))
                        ->content(fn (?Beneficiary $record) => collect($record?->legacy_review_json ?? [])
                            ->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)))
                            ->implode(' | ') ?: __('sanabel.beneficiary.legacy_review_empty'))
                        ->columnSpanFull(),
                ])->columns(2),

                /*
                 | T-69 — the four answers on the last two pages of the dossier.
                 |
                 | The visit consent is not decoration: it is what makes a
                 | donor visit permissible at all. And the privacy pair is two
                 | questions rather than one because their own sample answers
                 | «اطلاع: لا» and «موافقة: نعم», and a system that cannot
                 | record that cannot report it either.
                 */
                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_consents'))->schema([
                    Forms\Components\Toggle::make('donor_visit_consent')
                        ->label(__('sanabel.beneficiary.donor_visit_consent'))
                        ->helperText(__('sanabel.beneficiary.donor_visit_consent_help'))
                        ->live(),

                    Forms\Components\DatePicker::make('donor_visit_consent_on')
                        ->label(__('sanabel.beneficiary.donor_visit_consent_on'))
                        ->maxDate(now())
                        ->visible(fn (Forms\Get $get) => (bool) $get('donor_visit_consent')),

                    Forms\Components\Placeholder::make('privacy_policy_link')
                        ->label(__('sanabel.privacy.read_it'))
                        ->content(fn () => new HtmlString(
                            '<a href="'.e(url('/privacy')).'" target="_blank" rel="noopener" class="underline">'
                            .e(__('sanabel.privacy.page_title')).'</a> — '
                            .e(__('sanabel.privacy.show_to_family'))
                        ))
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('privacy_policy_seen')
                        ->label(__('sanabel.beneficiary.privacy_policy_seen'))
                        ->live(),

                    /*
                     | T-74 — the consent does not open before the policy was
                     | shown (13 Oct 2026). The model refuses it in any case;
                     | the screen closes the door rather than letting a clerk
                     | tick it and be told afterwards.
                     */
                    Forms\Components\Toggle::make('privacy_consent')
                        ->label(__('sanabel.beneficiary.privacy_consent'))
                        ->disabled(fn (Forms\Get $get) => $get('privacy_policy_seen') !== true)
                        ->helperText(fn (Forms\Get $get) => $get('privacy_policy_seen') !== true
                            ? __('sanabel.beneficiary.privacy_needs_seen_first')
                            : null)
                        ->live(),

                    // Kept for the rows the old-system import carries, where
                    // the old form was filled that way and refusing the row
                    // would lose the family rather than fix the form.
                    Forms\Components\Placeholder::make('privacy_contradiction')
                        ->label('')
                        ->content(__('sanabel.beneficiary.privacy_contradiction'))
                        ->visible(fn (Forms\Get $get) => (bool) $get('privacy_consent')
                            && $get('privacy_policy_seen') === false)
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('privacy_consent_on')
                        ->label(__('sanabel.beneficiary.privacy_consent_on'))
                        ->maxDate(now())
                        ->visible(fn (Forms\Get $get) => (bool) $get('privacy_consent')),

                    /*
                     | T-81 — stamped from the version in force, not typed. A
                     | clerk typing «1» where the policy says «1.0» would leave
                     | the question «which text did this family agree to»
                     | unanswerable, which is the one thing the column is for.
                     */
                    Forms\Components\TextInput::make('privacy_policy_version')
                        ->label(__('sanabel.privacy.version_in_force'))
                        ->helperText(__('sanabel.privacy.version_help'))
                        ->default(fn () => config('sanabel.privacy_policy.version'))
                        ->dehydrateStateUsing(fn () => config('sanabel.privacy_policy.version'))
                        ->disabled()
                        ->dehydrated()
                        ->visible(fn (Forms\Get $get) => (bool) $get('privacy_consent')),

                    Forms\Components\DatePicker::make('declaration_on')
                        ->label(__('sanabel.beneficiary.declaration_on'))
                        ->maxDate(now()),
                ])->columns(2),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_urgency'))->schema([
                    Forms\Components\DateTimePicker::make('urgency_deadline_at')
                        ->label(__('sanabel.beneficiary.urgency_deadline'))
                        ->helperText(__('sanabel.beneficiary.urgency_help')),

                    // Recorded, never computed from: the engine reads the
                    // income rows, as it always has.
                    Forms\Components\TextInput::make('declared_monthly_income')
                        ->label(__('sanabel.beneficiary.declared_monthly_income'))
                        ->helperText(__('sanabel.beneficiary.declared_income_help'))
                        ->numeric()->minValue(0)
                        ->suffix(config('sanabel.currency')),

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
                Tables\Columns\TextColumn::make('legacy_file_no')->label(__('sanabel.beneficiary.legacy_file_no'))
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                // T-65 — a file whose personal data has been destroyed, and
                // one with a request standing against it.
                Tables\Columns\TextColumn::make('anonymised_at')
                    ->label(__('sanabel.anonymise.badge'))
                    ->badge()->color('gray')
                    ->formatStateUsing(fn () => __('sanabel.anonymise.badge'))
                    ->placeholder('')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('case_classifications')
                    ->label(__('sanabel.beneficiary.case_classification'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __('sanabel.case_classification.'.$state) : '—')
                    ->toggleable(),
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
                        // The two waiting rooms read as in-flight, not idle.
                        'verified', 'pending_approval' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('fieldVerifier.name')
                    ->label(__('sanabel.verification.field_verified_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('endorser.name')
                    ->label(__('sanabel.verification.endorsed_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('duplicate_review_flag')
                    ->label(__('sanabel.beneficiary.duplicate_flag'))
                    ->boolean()
                    // A raised flag is the exception worth looking at. The
                    // default booleans read the other way round, so a list
                    // with nothing wrong came up a column of red crosses.
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('next_assessment_due_at')
                    ->label(__('sanabel.beneficiary.next_assessment'))
                    ->date()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sanabel.beneficiary.status'))
                    ->options(collect(Beneficiary::STATUSES)->mapWithKeys(fn ($s) => [$s => __('sanabel.status.'.$s)])),
                /*
                 | T-124 — «احتاج يكون المناطق بشكل رئيسي ثم القرى بشكل فرعي
                 | لكل شيء تابع له», 26 October.
                 |
                 | One list of a hundred and forty-seven places, the same
                 | flat list the registration form had before T-121. Two
                 | questions now: the area, and then the locality inside it.
                 | Choosing the area alone is a real answer — it means every
                 | file in that area — which is how a supervisor reads their
                 | own patch.
                 */
                Tables\Filters\Filter::make('place')
                    ->form([
                        Forms\Components\Select::make('area_id')
                            ->label(__('sanabel.beneficiary.region_area'))
                            ->options(fn () => Region::where('type', 'area')
                                ->orderBy('name_ar')
                                ->pluck('name_ar', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('region_id', null)),

                        Forms\Components\Select::make('region_id')
                            ->label(__('sanabel.beneficiary.region'))
                            ->options(fn (Forms\Get $get) => Region::where('parent_id', $get('area_id'))
                                ->orderBy('name_ar')
                                ->pluck('name_ar', 'id'))
                            ->searchable()
                            ->visible(fn (Forms\Get $get) => filled($get('area_id'))),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (filled($data['region_id'] ?? null)) {
                            return $query->where('region_id', $data['region_id']);
                        }

                        if (blank($data['area_id'] ?? null)) {
                            return $query;
                        }

                        // The area itself, and everything under it: a file
                        // may be attached to either.
                        return $query->where(fn (Builder $q) => $q
                            ->where('region_id', $data['area_id'])
                            ->orWhereIn('region_id', Region::where('parent_id', $data['area_id'])->select('id')));
                    })
                    ->indicateUsing(function (array $data) {
                        $place = Region::find($data['region_id'] ?? $data['area_id'] ?? null);

                        return $place ? __('sanabel.beneficiary.region').': '.$place->name_ar : null;
                    }),
                // T-63 — what they added it for.
                // A list column, so the filter asks the json whether it holds
                // the value rather than comparing one string to another.
                Tables\Filters\SelectFilter::make('case_classifications')
                    ->label(__('sanabel.beneficiary.case_classification'))
                    ->options(collect(Beneficiary::CASE_CLASSIFICATIONS)
                        ->mapWithKeys(fn (string $k) => [$k => __('sanabel.case_classification.'.$k)])
                        ->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereJsonContains('case_classifications', $data['value'])
                        : $query),
                Tables\Filters\TernaryFilter::make('duplicate_review_flag')
                    ->label(__('sanabel.beneficiary.duplicate_flag')),

                /*
                 | T-126 — the files this account opened itself.
                 |
                 | The region scope answers «my village»; this answers «my
                 | work», which is what a delegate standing in a village with
                 | three other delegates actually needs. It is also what the
                 | dashboard cards count, so a card and the list it opens now
                 | show the same number.
                 */
                Tables\Filters\Filter::make('mine')
                    ->label(__('sanabel.beneficiary.mine'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('created_by', auth()->id())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * T-121 — the four marital states, written to match who is being asked.
     *
     * The keys are the same whichever list is drawn: what is saved for a
     * widow and for a widower is `widowed`, and only the word on the screen
     * changes.
     */
    public static function maritalOptions(?string $gender): array
    {
        return $gender === 'female'
            ? __('sanabel.marital_status_f')
            : __('sanabel.marital_status');
    }

    /** Years old, from a date of birth, or null when there is none. */
    public static function ageFromDate(mixed $birthDate): ?int
    {
        return filled($birthDate) ? Carbon::parse($birthDate)->age : null;
    }

    /**
     * A dependant's age. The survey gives a full date and the engine reads the
     * year, so either may be the one that was filled in.
     */
    public static function memberAge(Forms\Get $get): ?int
    {
        if ($age = self::ageFromDate($get('birth_date'))) {
            return $age;
        }

        $year = (int) $get('birth_year');

        return $year > 0 ? (int) date('Y') - $year : null;
    }

    /** T-59 — a file whose payments are already flowing. */
    public static function paymentDetailsLocked(?Beneficiary $record): bool
    {
        return $record !== null
            && in_array($record->status, ChangeRequest::LIVE_STATUSES, true);
    }

    /**
     * Put an encrypted column back on the rows a repeater fills from.
     *
     * `$hidden` keeps these out of `attributesToArray()`, which is the array
     * Filament fills a form from, so they arrived at the screen empty and
     * saving wrote the blanks back over them — the same bug
     * EncryptedFieldEditTest caught on the family's own phone and wallet.
     * Each column is named at its own call site, so nothing is unhidden that
     * the screen does not actually show.
     *
     * @param  array<int,string>  $columns
     */
    private static function unhide($query, array $columns)
    {
        return $query->afterQuery(fn ($records) => $records->each->makeVisible($columns));
    }

    /** dependent and unable_to_earn are always derived, never taken from the form. */
    private static function deriveMemberFlags(array $data): array
    {
        $age = (int) date('Y') - (int) ($data['birth_year'] ?? date('Y'));

        $data['person_class'] = DependencyRules::personClass($age, $data['relation'] ?? null);
        $data['dependent'] = DependencyRules::isDependent($age, (bool) ($data['is_student'] ?? false), false);
        $data['unable_to_earn'] = DependencyRules::isUnableToEarn((bool) ($data['has_documented_condition'] ?? false));

        return $data;
    }

    public static function getRelations(): array
    {
        return [BeneficiaryResource\RelationManagers\DocumentsRelationManager::class];
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
