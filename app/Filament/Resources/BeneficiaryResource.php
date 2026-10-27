<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BeneficiaryResource\Pages;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Models\HouseholdMember;
use App\Models\Region;
use App\Models\Scopes\RegionScope;
use App\Models\User;
use App\Services\CoverageService;
use App\Services\DependencyRules;
use App\Services\FamilyAges;
use App\Services\FamilyNames;
use App\Services\IdentityFormat;
use App\Services\Money;
use App\Services\RankingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            /*
             | T-159 — «لازم يتم ذكر التعديل انه تم بواسطة مشرف المنطقة مع
             | اسم المستخدم, اي ان كل التعديلات يجب ان تكون مسجلة», 29 Oct.
             |
             | Every write has been logged since the beginning (rule 4), and
             | the log is deliberately narrow: it names who did what to which
             | family's file, so only the accounts that oversee may read it
             | whole. What was missing is the one line everybody working the
             | file needs — who touched it last, in what capacity, and when.
             | It is read from that same log, and it is not a field anybody
             | can type into.
             */
            Forms\Components\Placeholder::make('last_edit')
                ->label(__('sanabel.beneficiary.last_edit'))
                ->content(fn (?Beneficiary $record) => self::lastEditLine($record))
                ->visible(fn (?Beneficiary $record) => filled(self::lastEditLine($record)))
                ->columnSpanFull(),

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

                    /*
                     | T-175 — the three boxes a child's row reads from. Each
                     | one pushes the correction down into the rows the moment
                     | it is made, so «يتم تعبئة اسم الأم مباشر بعد تعبئة
                     | معلومات أمه» is true in the same sitting and not only
                     | after a save and a reload.
                     */
                    Forms\Components\TextInput::make('first_name')
                        ->label(__('sanabel.beneficiary.first_name'))
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::refreshInheritedNames($get, $set)),

                    Forms\Components\TextInput::make('father_name')->label(__('sanabel.beneficiary.father_name'))->required(),

                    Forms\Components\TextInput::make('family_name')
                        ->label(__('sanabel.beneficiary.family_name'))
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::refreshInheritedNames($get, $set)),

                    Forms\Components\Select::make('gender')
                        ->label(__('sanabel.beneficiary.gender'))
                        ->options(__('sanabel.gender'))
                        // T-121 — the marital states below are written to match.
                        // T-175 — and which parent a child's row reads from.
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::refreshInheritedNames($get, $set)),

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
                        // T-130 — see openableBy(): a scoped account is offered
                        // its own area only, because a file filed outside it
                        // disappears the moment it is saved.
                        ->options(fn () => self::areaOptions())
                        ->default(fn () => array_key_first(self::areaOptions()))
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
                        ->options(fn (Forms\Get $get) => self::localityOptions($get('region_area_id')))
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

                    /*
                     | T-128 — «مافي بيانات الزوجة, او بيانات الزوج, يجب
                     | تصحيح الامر وتضمينهم حتى لو متوفية يتم ذكرهم»,
                     | 27 October.
                     |
                     | The file said «أرمل» and stopped there. Every dependant
                     | in the household has carried her husband's name and
                     | number since T-69, and the one person the file is named
                     | after carried neither.
                     |
                     | Asked of any head who is not single, and of a widow
                     | exactly as of a wife: the words change with who is
                     | being asked, the questions do not. A deceased spouse is
                     | named and is not a household member, so no money moves.
                     */
                    Forms\Components\Fieldset::make('spouse')
                        ->label(fn (Forms\Get $get) => self::spouseLabel($get('gender')))
                        ->visible(fn (Forms\Get $get) => filled($get('marital_status'))
                            && $get('marital_status') !== 'single')
                        ->schema([
                            /*
                             | T-152 — «الزوج لم يتم اضافته … لازم بس يسجل
                             | متزوجه او ارمله او مطلقه يكتب اسم الزوج اجباري
                             | مع معلوماته», 29 October.
                             |
                             | The block has been asked since T-128 and could
                             | be walked past. A file that says «أرملة» and
                             | names nobody is a file the association cannot
                             | check against the civil registry, so the name is
                             | required wherever the block is shown. The
                             | identity number is not: a husband who died in
                             | 2013 may have left none behind, and a file is
                             | not refused over a paper that no longer exists.
                             */
                            Forms\Components\TextInput::make('spouse_name_ar')
                                ->label(fn (Forms\Get $get) => self::spouseNameLabel($get('gender')))
                                ->required()
                                ->validationMessages(['required' => __('sanabel.beneficiary.spouse_required')])
                                ->helperText(fn (Forms\Get $get) => $get('marital_status') === 'widowed'
                                    ? __('sanabel.beneficiary.spouse_deceased_help')
                                    : __('sanabel.beneficiary.spouse_member_help'))
                                // T-175 — the other parent of every child in
                                // the household is named in this one box.
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::refreshInheritedNames($get, $set)),

                            Forms\Components\TextInput::make('spouse_national_id_encrypted')
                                ->label(__('sanabel.beneficiary.spouse_national_id'))
                                ->maxLength(IdentityFormat::NATIONAL_ID_DIGITS)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::NATIONAL_ID_DIGITS,
                                    __('sanabel.cases.national_id_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state)),

                            /*
                             | T-152 — «ولما يتم اختيار ارملة يتم اعطاء خيار
                             | ارفاق شهادة وفاة ولما يتم اختيار مطلقة يظهر
                             | خيار ارفاق صك الطلاق».
                             |
                             | Offered where the status is answered rather than
                             | on the documents tab, which is a different screen
                             | and a later day. The paper itself still goes
                             | through the one upload path with a type and size
                             | gate on it, and lands on «وثائق الأسرة» under its
                             | own kind.
                             */
                            Forms\Components\FileUpload::make('spouse_proof')
                                ->label(fn (Forms\Get $get) => $get('marital_status') === 'widowed'
                                    ? __('sanabel.beneficiary.spouse_proof_death')
                                    : __('sanabel.beneficiary.spouse_proof_divorce'))
                                ->helperText(__('sanabel.beneficiary.spouse_proof_help'))
                                ->visible(fn (Forms\Get $get) => in_array($get('marital_status'), ['widowed', 'divorced'], true))
                                ->maxSize(8192)
                                ->storeFiles(false)
                                ->columnSpanFull(),
                        ])->columns(2)->columnSpanFull(),

                    /*
                     | T-127 — «الافضل يكون نعم, لا, يبحث عن عمل», 27 October.
                     |
                     | A yes/no toggle made a man who would take a job this
                     | week answer «لا», which is also the answer of a
                     | household with nobody of working age in it. The
                     | association runs a job market for the first of those
                     | two, and could not tell them apart.
                     */
                    Forms\Components\Select::make('work_status')
                        // T-152 — asked of every marital status, and worded for
                        // whoever is being asked.
                        ->label(fn (Forms\Get $get) => self::workLabel($get('gender')))
                        ->options(__('sanabel.work_status'))
                        ->live()
                        /*
                         | A file written before this task answered the old
                         | boolean and nothing else. The migration carried
                         | those rows across; this carries the ones that
                         | arrive afterwards from an import or the offline
                         | sync, so the screen never shows an empty question
                         | over an answer the file is holding.
                         */
                        ->afterStateHydrated(function (Forms\Components\Select $component, ?Beneficiary $record) {
                            $component->state($record?->work_status ?? match ($record?->works) {
                                true => 'yes',
                                false => 'no',
                                default => null,
                            });
                        })
                        ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                            if (in_array($value, ['yes', 'seeking'], true)
                                && ! DependencyRules::answersAsAdult(self::ageFromDate($get('birth_date')))) {
                                $fail(__('sanabel.cases.too_young_to_work'));
                            }
                        }),

                    // «في حال نعم يذكر المهنة والراتب الحالي».
                    Forms\Components\TextInput::make('profession_ar')
                        ->label(__('sanabel.beneficiary.profession_ar'))
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'yes'),

                    Forms\Components\TextInput::make('workplace_ar')
                        ->label(__('sanabel.beneficiary.workplace_ar'))
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'yes'),

                    // T-121 — what the work pays, asked where the work is.
                    Forms\Components\TextInput::make('work_income')
                        ->label(__('sanabel.beneficiary.work_income'))
                        ->helperText(__('sanabel.beneficiary.work_income_help'))
                        ->numeric()->minValue(0)
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'yes'),

                    /*
                     | «في حال يبحث عن عمل تظهر عنده ما هي المهنة التي يستطيع
                     | العمل بها وهل لديه شهادة وكم سنوات الخبرة واضف خيار هل
                     | يستطيع تدريس القرأن الكريم». Four questions that only
                     | mean anything of somebody who is looking.
                     */
                    Forms\Components\TextInput::make('sought_trade_ar')
                        ->label(__('sanabel.beneficiary.sought_trade_ar'))
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'seeking'),

                    Forms\Components\Toggle::make('has_certificate')
                        ->label(__('sanabel.beneficiary.has_certificate'))
                        ->live()
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'seeking'),

                    Forms\Components\TextInput::make('certificate_ar')
                        ->label(__('sanabel.beneficiary.certificate_ar'))
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'seeking'
                            && (bool) $get('has_certificate')),

                    Forms\Components\TextInput::make('experience_years')
                        ->label(__('sanabel.beneficiary.experience_years'))
                        ->numeric()->minValue(0)->maxValue(70)
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'seeking'),

                    Forms\Components\Toggle::make('can_teach_quran')
                        ->label(__('sanabel.beneficiary.can_teach_quran'))
                        ->helperText(__('sanabel.beneficiary.can_teach_quran_help'))
                        ->visible(fn (Forms\Get $get) => $get('work_status') === 'seeking'),

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

                    /*
                     | T-177 — the approver's own note, where everyone who may
                     | open the file can read it: it is often an instruction to
                     | whoever fills the file in («الدخل يحتاج ورقة»), and a
                     | note nobody but its author sees is not worth writing.
                     |
                     | Read-only here on purpose. It is written from the
                     | «التصنيف وملاحظة الإدارة» button, which asks
                     | `approve_case`, so a clerk cannot answer the management
                     | in the management's own box. Hidden while empty rather
                     | than drawn blank on every file.
                     */
                    Forms\Components\Textarea::make('management_note_ar')
                        ->label(__('sanabel.classify.note'))
                        ->helperText(__('sanabel.classify.note_read_help'))
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?Beneficiary $record) => filled($record?->management_note_ar))
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
                            ['national_id_encrypted', 'spouse_national_id_encrypted', 'doctor_phone_encrypted'],
                        ))
                        ->label(__('sanabel.beneficiary.members'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_members'))
                        ->schema([
                            Forms\Components\TextInput::make('name_ar')
                                ->label(__('sanabel.household_member.name'))
                                ->helperText(__('sanabel.household_member.name_help'))
                                ->required(),

                            /*
                             | T-175 — «فقط الأب مطلوب الكنية والابن ياخد كنية
                             | الأب … لكن للابن يتم تعبئة اسم الأم مباشر بعد
                             | تعبئة معلومات أمه», 1 November.
                             |
                             | The three names a son or a daughter does not
                             | have to be asked for, because the file already
                             | holds all three: the surname and one parent are
                             | the head's own, and the other parent is the
                             | spouse the head was required to name in T-152.
                             | Asking again is three chances to spell one
                             | family's name three ways.
                             |
                             | Filled and locked for a child; asked of anybody
                             | else, which is the second half of the same note:
                             | «إذا كان المعال غير الابن والبنت نحتاج اسم الأب
                             | والكنية». A wife is asked for her surname too,
                             | because their rule says so and because it is
                             | hers.
                             |
                             | `dehydrateStateUsing` decides what is saved, so
                             | a child's three names are read from the file at
                             | the moment of saving however the boxes were
                             | filled, and a head who is renamed does not leave
                             | her children carrying the old name.
                             */
                            Forms\Components\TextInput::make('family_name_ar')
                                ->label(__('sanabel.household_member.family_name'))
                                ->helperText(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? __('sanabel.household_member.taken_from_the_file')
                                    : __('sanabel.household_member.family_name_help'))
                                ->disabled(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation')))
                                ->dehydrated()
                                ->afterStateHydrated(fn (Forms\Components\TextInput $component, Forms\Get $get) => self::fillInheritedName(
                                    $component,
                                    FamilyNames::surnameFor($get('relation'), $get('../../family_name')),
                                ))
                                ->dehydrateStateUsing(fn (?string $state, Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? FamilyNames::surnameFor($get('relation'), $get('../../family_name'))
                                    : $state),

                            Forms\Components\TextInput::make('father_name_ar')
                                ->label(__('sanabel.household_member.father_name'))
                                ->helperText(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? __('sanabel.household_member.taken_from_the_file')
                                    : __('sanabel.household_member.father_name_help'))
                                ->disabled(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation')))
                                ->dehydrated()
                                ->afterStateHydrated(fn (Forms\Components\TextInput $component, Forms\Get $get) => self::fillInheritedName(
                                    $component,
                                    self::parentName($get, 'father'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state, Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? self::parentName($get, 'father')
                                    : $state),

                            Forms\Components\TextInput::make('mother_name_ar')
                                ->label(__('sanabel.household_member.mother_name'))
                                ->helperText(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? __('sanabel.household_member.taken_from_the_file')
                                    : __('sanabel.household_member.mother_name_help'))
                                ->disabled(fn (Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation')))
                                ->dehydrated()
                                ->afterStateHydrated(fn (Forms\Components\TextInput $component, Forms\Get $get) => self::fillInheritedName(
                                    $component,
                                    self::parentName($get, 'mother'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state, Forms\Get $get) => FamilyNames::inheritsFromHead($get('relation'))
                                    ? self::parentName($get, 'mother')
                                    : $state),

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

                            /*
                             | T-129 — «الربط بين صلة القرابة والجنس بحيث تكون
                             | ابنة والجنس انثى», 27 October.
                             |
                             | «ابنة» and «ذكر» was a row the form accepted,
                             | and the two words beside each other are the
                             | kind of contradiction nobody reads twice. The
                             | kinship answers the gender where it can: a
                             | daughter, a mother, a sister and a wife are
                             | female and a son, a father and a brother are
                             | male. «رب الأسرة» and «أخرى» say nothing about
                             | it, so they leave it as it was.
                             */
                            Forms\Components\Select::make('relation')
                                ->label(__('sanabel.household_member.relation'))
                                ->options(__('sanabel.relation'))
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    if ($gender = self::genderOfRelation($state)) {
                                        $set('gender', $gender);
                                    }

                                    $set('person_class', DependencyRules::personClass(
                                        (int) date('Y') - (int) ($get('birth_year') ?: date('Y')),
                                        $state,
                                    ));

                                    // T-175 — the three shared names follow the
                                    // kinship the moment it is answered, so the
                                    // researcher sees what the file already
                                    // knows instead of typing it again.
                                    if (FamilyNames::inheritsFromHead($state)) {
                                        $set('family_name_ar', FamilyNames::surnameFor($state, $get('../../family_name')));
                                        $set('father_name_ar', self::parentName($get, 'father', $state));
                                        $set('mother_name_ar', self::parentName($get, 'mother', $state));
                                    }
                                }),
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
                                // T-153 — the same refusal wherever the year
                                // was typed: the date fills the year, so one
                                // of the two is always the answer given.
                                ->rule(fn (Forms\Get $get) => self::ageAgainstHead($get))
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
                                    // T-129 — the age moved, so the rules'
                                    // own answer about dependency moves with
                                    // it. It stays editable afterwards.
                                    $set('dependent', DependencyRules::isDependent(
                                        (int) date('Y') - $year,
                                        $get('occupation') === 'student',
                                        false,
                                    ));
                                }),

                            Forms\Components\TextInput::make('birth_year')
                                ->label(__('sanabel.household_member.birth_year'))
                                ->numeric()->minValue(1900)->maxValue((int) date('Y'))
                                ->required()
                                // T-153 — a son born before his mother.
                                ->rule(fn (Forms\Get $get) => self::ageAgainstHead($get))
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                    if ($state) {
                                        $set('person_class', DependencyRules::personClass(
                                            (int) date('Y') - (int) $state,
                                            $get('relation'),
                                        ));
                                        $set('dependent', DependencyRules::isDependent(
                                            (int) date('Y') - (int) $state,
                                            $get('occupation') === 'student',
                                            false,
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
                            // T-129 — and asked here rather than in the status
                            // list: an orphan is also a student or a child.
                            /*
                             | T-153 — «شرط تفعيل اليتيم تكون الام ارملة وعمره
                             | تحت 15», 29 October. Asked only where it can be
                             | true: a household headed by a widow or a widower,
                             | and a child under the age this form starts
                             | answering as an adult.
                             */
                            Forms\Components\Toggle::make('is_orphan')
                                ->label(__('sanabel.household_member.is_orphan'))
                                ->visible(fn (Forms\Get $get) => FamilyAges::orphanMayBeAsked(
                                    self::memberAge($get),
                                    $get('../../marital_status'),
                                )),

                            /*
                             | T-129 — the status decides what is asked next.
                             |
                             | It used to be a label with nothing behind it,
                             | sitting beside two toggles that could contradict
                             | it: «موظف» and «لا يعمل» in the same row. The
                             | toggles are gone — `works` and `is_student` are
                             | derived from this answer in the model — and each
                             | status now opens its own questions and no others.
                             */
                            Forms\Components\Select::make('occupation')
                                ->label(__('sanabel.household_member.occupation'))
                                ->options(__('sanabel.occupation'))
                                ->live()
                                ->afterStateUpdated(fn ($state, Forms\Get $get, Forms\Set $set) => $set(
                                    'dependent',
                                    DependencyRules::isDependent(
                                        (int) self::memberAge($get),
                                        $state === 'student',
                                        false,
                                    ),
                                ))
                                ->rule(fn (Forms\Get $get) => function ($attribute, $value, $fail) use ($get) {
                                    if (in_array($value, HouseholdMember::WORKING_OCCUPATIONS, true)
                                        && ! DependencyRules::answersAsAdult(self::memberAge($get))) {
                                        $fail(__('sanabel.cases.too_young_to_work'));
                                    }
                                }),

                            /*
                             | T-155 — «أخرى» is an answer, not the end of the
                             | question. The kinship list has asked which since
                             | the beginning; the status list is the one that
                             | decides what is asked next, and it did not.
                             */
                            Forms\Components\TextInput::make('occupation_other_ar')
                                ->label(__('sanabel.household_member.occupation_other_ar'))
                                ->required()
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'other'),

                            // طالب — «بأي مرحلة وأي صف».
                            Forms\Components\Select::make('school_stage')
                                ->label(__('sanabel.household_member.school_stage'))
                                ->options(__('sanabel.school_stage'))
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'student'),

                            Forms\Components\TextInput::make('school_grade_ar')
                                ->label(__('sanabel.household_member.school_grade_ar'))
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'student'),

                            // موظف أو عامل — «مكان العمل والراتب الشهري».
                            Forms\Components\TextInput::make('workplace_ar')
                                ->label(__('sanabel.household_member.workplace_ar'))
                                ->visible(fn (Forms\Get $get) => in_array(
                                    $get('occupation'),
                                    HouseholdMember::WORKING_OCCUPATIONS,
                                    true,
                                )),

                            // T-121 — what this one's work pays.
                            Forms\Components\TextInput::make('work_income')
                                ->label(__('sanabel.household_member.work_income'))
                                ->numeric()->minValue(0)
                                ->visible(fn (Forms\Get $get) => in_array(
                                    $get('occupation'),
                                    HouseholdMember::WORKING_OCCUPATIONS,
                                    true,
                                )),

                            // باحث عن عمل — the same four answers the head is
                            // asked (T-127), asked of whoever is looking.
                            Forms\Components\TextInput::make('sought_trade_ar')
                                ->label(__('sanabel.household_member.sought_trade_ar'))
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'jobseeker'),

                            Forms\Components\Toggle::make('has_certificate')
                                ->label(__('sanabel.household_member.has_certificate'))
                                ->live()
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'jobseeker'),

                            Forms\Components\TextInput::make('certificate_ar')
                                ->label(__('sanabel.household_member.certificate_ar'))
                                // Shown to a jobseeker who has one — and to any
                                // row that already carries one, so an answer
                                // given before this task is not hidden by it.
                                ->visible(fn (Forms\Get $get) => ($get('occupation') === 'jobseeker'
                                    && (bool) $get('has_certificate'))
                                    || filled($get('certificate_ar'))),

                            Forms\Components\TextInput::make('experience_years')
                                ->label(__('sanabel.household_member.experience_years'))
                                ->numeric()->minValue(0)->maxValue(70)
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'jobseeker'),

                            Forms\Components\Toggle::make('can_teach_quran')
                                ->label(__('sanabel.household_member.can_teach_quran'))
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'jobseeker'),

                            // متقاعد — «يتم ذكر الراتب التقاعدي».
                            Forms\Components\TextInput::make('pension_income')
                                ->label(__('sanabel.household_member.pension_income'))
                                ->helperText(__('sanabel.household_member.pension_help'))
                                ->numeric()->minValue(0)
                                ->suffix(Money::short())
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'retired'),

                            /*
                             | غير قادر على العمل — «يظهر الوضع الصحي الخاص مع
                             | رفع ملف او وصف مع رقم الطبيب».
                             |
                             | The description and the doctor are asked here,
                             | where the answer is given. The report itself is
                             | uploaded under «الوثائق» as a medical paper,
                             | which is the one upload path in this system with
                             | a type and size gate on it, and the banded
                             | assessment the need engine reads stays in the
                             | health step. The helper text says all three.
                             */
                            Forms\Components\Textarea::make('condition_description_ar')
                                ->label(__('sanabel.household_member.condition_description_ar'))
                                ->helperText(__('sanabel.household_member.condition_evidence_help'))
                                ->rows(2)
                                ->columnSpanFull()
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'unable'),

                            Forms\Components\TextInput::make('doctor_phone_encrypted')
                                ->label(__('sanabel.household_member.doctor_phone'))
                                ->tel()
                                ->maxLength(20)
                                ->rule(fn () => IdentityFormat::lengthRule(
                                    IdentityFormat::PHONE_DIGITS,
                                    __('sanabel.cases.phone_digits'),
                                ))
                                ->dehydrateStateUsing(fn (?string $state) => IdentityFormat::digits($state))
                                ->visible(fn (Forms\Get $get) => $get('occupation') === 'unable'),

                            Forms\Components\Toggle::make('smokes')
                                ->label(__('sanabel.household_member.smokes')),

                            Forms\Components\Toggle::make('is_quran_memorizer')
                                ->label(__('sanabel.household_member.is_quran_memorizer')),

                            Forms\Components\TextInput::make('skills_ar')
                                ->label(__('sanabel.household_member.skills_ar')),

                            Forms\Components\Toggle::make('has_documented_condition')
                                ->label(__('sanabel.household_member.has_documented_condition'))
                                ->helperText(__('sanabel.household_member.condition_help'))
                                ->live()
                                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set(
                                    'unable_to_earn',
                                    DependencyRules::isUnableToEarn((bool) $state),
                                )),

                            /*
                             | T-129 — «عند معال او عاجز عن العمل يجب ان يكون
                             | قابلة للتعديل».
                             |
                             | They were two read-only lines showing what the
                             | rules make of the row. The rules still fill them,
                             | and refill them whenever the age or the status
                             | that feeds them changes — but the researcher who
                             | sat with the family can now correct the answer,
                             | which is what the association asked for.
                             |
                             | Both move money: `dependent` feeds the
                             | dependants ratio in the score and
                             | `unable_to_earn` opens an adjustment in the need.
                             | So the screen says so under each one, and every
                             | change is in the audit log like any other.
                             */
                            Forms\Components\Toggle::make('dependent')
                                ->label(__('sanabel.household_member.dependent'))
                                ->helperText(__('sanabel.household_member.dependent_help')),

                            Forms\Components\Toggle::make('unable_to_earn')
                                ->label(__('sanabel.household_member.unable_to_earn'))
                                ->helperText(__('sanabel.household_member.unable_help')),

                            Forms\Components\Textarea::make('notes_ar')->label(__('sanabel.household_member.notes'))->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::deriveMemberFlags($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::deriveMemberFlags($data)),
                ]),

                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_income'))->schema([
                    /*
                     | T-156 — «بالبداية يتم تفعيل متل Radio button هل يوجد
                     | دخل: اذا كان نعم يظهر باقي تفاصيل الادخال واذا كان لا
                     | يتم اخفاء تفاصيل الادخال للانتقال للصفحة التالية», 29
                     | October.
                     |
                     | T-130 made an empty table mean «لا», which stopped the
                     | form refusing a file over a blank row nobody asked for.
                     | It also left the researcher with no way to say they had
                     | asked: an empty table and an unasked question looked
                     | identical. The question is asked outright now, and it
                     | must be answered before the page turns.
                     |
                     | Answering «لا» empties the table, so the file cannot say
                     | «لا» while holding rows that say otherwise.
                     */
                    Forms\Components\Radio::make('has_income')
                        ->label(__('sanabel.beneficiary.has_income_q'))
                        ->options([
                            'yes' => __('sanabel.beneficiary.answer_yes'),
                            'no' => __('sanabel.beneficiary.answer_no'),
                        ])
                        ->inline()
                        ->inlineLabel(false)
                        ->required()
                        ->validationMessages(['required' => __('sanabel.beneficiary.answer_required')])
                        ->live()
                        // Kept in the submitted data and taken out by the page:
                        // «لا» has to empty the table it answers for.
                        ->afterStateHydrated(fn (Forms\Components\Radio $component, ?Beneficiary $record) => $component
                            ->state(self::gateAnswer($record, 'incomes')))
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state === 'no') {
                                $set('incomes', []);
                            }
                        })
                        ->helperText(__('sanabel.beneficiary.answered_no_clears'))
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('incomes')
                        ->visible(fn (Forms\Get $get) => $get('has_income') === 'yes')
                        ->relationship()
                        ->label(__('sanabel.beneficiary.incomes'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_incomes'))
                        ->schema([
                            Forms\Components\TextInput::make('source_type')->label(__('sanabel.income.source'))->required(),
                            Forms\Components\TextInput::make('amount')
                                ->label(__('sanabel.income.amount'))
                                ->numeric()->minValue(0)->required()
                                ->suffix(Money::short()),
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
                    /*
                     | T-130 — the housing page is written when the visit has
                     | happened, and a file saved before it is a draft, not an
                     | error. The row is created once the type is answered;
                     | until then there is no housing row at all, rather than
                     | one full of zeroes that the score would read as a sound
                     | house. The need engine already reads «no housing» as
                     | «not renting».
                     */
                    Forms\Components\Group::make()
                        ->relationship('housing', condition: fn (?array $state) => filled($state['housing_type'] ?? null))
                        ->schema([
                            Forms\Components\Select::make('housing_type')
                                ->label(__('sanabel.housing.type'))
                                ->helperText(__('sanabel.housing.type_help'))
                                ->options(__('sanabel.housing_type'))->live(),

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
                                ->suffix(Money::short())
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

                            /*
                             | T-169 — the two questions the association wrote
                             | out by hand on 31 October, in place of T-164's
                             | single grade.
                             |
                             | The first judges the house. The second is the
                             | one a donor can act on: «برّاد، أغطية» says what
                             | to give, where a grade only says how bad it is.
                             | Both are lists, so both may be published; the
                             | sentence below them stays for the committee, and
                             | so does whatever is typed beside «غيرها».
                             */
                            Forms\Components\Select::make('home_impression')
                                ->label(__('sanabel.housing.home_impression'))
                                ->helperText(__('sanabel.housing.home_impression_help'))
                                ->options(__('sanabel.home_impression')),

                            Forms\Components\CheckboxList::make('missing_items')
                                ->label(__('sanabel.housing.missing_items'))
                                ->helperText(__('sanabel.housing.missing_items_help'))
                                ->options(__('sanabel.missing_items'))
                                ->live()
                                ->columns(3)
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('missing_items_other_ar')
                                ->label(__('sanabel.housing.missing_items_other_ar'))
                                ->helperText(__('sanabel.housing.missing_items_other_help'))
                                ->visible(fn (Forms\Get $get) => in_array('other', (array) $get('missing_items'), true))
                                ->columnSpanFull(),

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
                    /*
                     | T-156 — «بالبداية يتم تفعيل متل Radio button هل يوجد
                     | دخل: اذا كان نعم يظهر باقي تفاصيل الادخال واذا كان لا
                     | يتم اخفاء تفاصيل الادخال للانتقال للصفحة التالية», 29
                     | October.
                     |
                     | T-130 made an empty table mean «لا», which stopped the
                     | form refusing a file over a blank row nobody asked for.
                     | It also left the researcher with no way to say they had
                     | asked: an empty table and an unasked question looked
                     | identical. The question is asked outright now, and it
                     | must be answered before the page turns.
                     |
                     | Answering «لا» empties the table, so the file cannot say
                     | «لا» while holding rows that say otherwise.
                     */
                    Forms\Components\Radio::make('has_health')
                        ->label(__('sanabel.beneficiary.has_health_q'))
                        ->options([
                            'yes' => __('sanabel.beneficiary.answer_yes'),
                            'no' => __('sanabel.beneficiary.answer_no'),
                        ])
                        ->inline()
                        ->inlineLabel(false)
                        ->required()
                        ->validationMessages(['required' => __('sanabel.beneficiary.answer_required')])
                        ->live()
                        // Kept in the submitted data and taken out by the page:
                        // «لا» has to empty the table it answers for.
                        ->afterStateHydrated(fn (Forms\Components\Radio $component, ?Beneficiary $record) => $component
                            ->state(self::gateAnswer($record, 'healthRecords')))
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state === 'no') {
                                $set('healthRecords', []);
                            }
                        })
                        ->helperText(__('sanabel.beneficiary.answered_no_clears'))
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('healthRecords')
                        ->visible(fn (Forms\Get $get) => $get('has_health') === 'yes')
                        ->relationship(modifyQueryUsing: fn ($query) => self::unhide(
                            $query,
                            ['description_ar', 'doctor_phone_encrypted'],
                        ))
                        ->label(__('sanabel.beneficiary.health'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_health'))
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
                                ->suffix(Money::short()),
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

                            /*
                             | T-164 — «نوع الأدوية» out of a list, which is
                             | what travels to the donor's page. The names of
                             | the medicines stay in the box above, for the
                             | committee. More than one may be true at once.
                             */
                            Forms\Components\CheckboxList::make('medicine_kinds')
                                ->label(__('sanabel.health.medicine_kinds'))
                                ->helperText(__('sanabel.health.medicine_kinds_help'))
                                ->options(__('sanabel.medicine_kinds'))
                                ->columns(2)
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('description_ar')->label(__('sanabel.health.description'))->columnSpanFull(),
                        ])->columns(2),

                    /*
                     | T-154 — «في حال اختيار حالة تمنع العمل لازم يتم تفعيل
                     | خيار ارفاق تقرير طبي», 29 October.
                     |
                     | The claim that somebody cannot work is the heaviest
                     | single answer on this form: it moves the person out of
                     | the earning class and into the need. The paper that
                     | carries it was asked for in words under the member's own
                     | row — «يُرفع التقرير ضمن الوثائق» — and uploaded from
                     | another screen on another day, if at all.
                     |
                     | It appears the moment the household says somebody cannot
                     | work, and files itself as a medical paper on «وثائق
                     | الأسرة». It is offered, not demanded: a family that has
                     | not been to a doctor yet still has to be registered.
                     */
                    Forms\Components\FileUpload::make('medical_report')
                        ->label(__('sanabel.beneficiary.medical_report'))
                        ->helperText(__('sanabel.beneficiary.medical_report_help'))
                        ->visible(fn (Forms\Get $get) => self::claimsUnableToWork($get))
                        ->maxSize(8192)
                        ->storeFiles(false)
                        ->columnSpanFull(),
                ]),

                // T-55 — what the family owns and what they owe. Each row is
                // the record of what was said; the two questions the
                // association answered on 11 October are asked here, and
                // nothing is inferred from a row without them.
                Forms\Components\Wizard\Step::make(__('sanabel.beneficiary.step_assets'))->schema([
                    /*
                     | T-156 — «بالبداية يتم تفعيل متل Radio button هل يوجد
                     | دخل: اذا كان نعم يظهر باقي تفاصيل الادخال واذا كان لا
                     | يتم اخفاء تفاصيل الادخال للانتقال للصفحة التالية», 29
                     | October.
                     |
                     | T-130 made an empty table mean «لا», which stopped the
                     | form refusing a file over a blank row nobody asked for.
                     | It also left the researcher with no way to say they had
                     | asked: an empty table and an unasked question looked
                     | identical. The question is asked outright now, and it
                     | must be answered before the page turns.
                     |
                     | Answering «لا» empties the table, so the file cannot say
                     | «لا» while holding rows that say otherwise.
                     */
                    Forms\Components\Radio::make('has_assets')
                        ->label(__('sanabel.beneficiary.has_assets_q'))
                        ->options([
                            'yes' => __('sanabel.beneficiary.answer_yes'),
                            'no' => __('sanabel.beneficiary.answer_no'),
                        ])
                        ->inline()
                        ->inlineLabel(false)
                        ->required()
                        ->validationMessages(['required' => __('sanabel.beneficiary.answer_required')])
                        ->live()
                        // Kept in the submitted data and taken out by the page:
                        // «لا» has to empty the table it answers for.
                        ->afterStateHydrated(fn (Forms\Components\Radio $component, ?Beneficiary $record) => $component
                            ->state(self::gateAnswer($record, 'assets')))
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            if ($state === 'no') {
                                $set('assets', []);
                            }
                        })
                        ->helperText(__('sanabel.beneficiary.answered_no_clears'))
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('assets')
                        ->visible(fn (Forms\Get $get) => $get('has_assets') === 'yes')
                        ->relationship()
                        ->label(__('sanabel.asset.plural'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_assets'))
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
                                ->suffix(Money::short()),

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
                                .' '.Money::short()
                            : '—'),

                    Forms\Components\Repeater::make('livestock')
                        ->relationship()
                        ->label(__('sanabel.livestock.plural'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_livestock'))
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
                                ->suffix(Money::short())
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
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_debts'))
                        ->schema([
                            Forms\Components\TextInput::make('creditor_name_ar')
                                ->label(__('sanabel.debt.creditor_name_ar'))
                                ->required(),

                            Forms\Components\TextInput::make('amount')
                                ->label(__('sanabel.debt.amount'))
                                ->helperText(__('sanabel.debt.engine_help'))
                                ->numeric()->minValue(0)->required()
                                ->suffix(Money::short()),

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
                        // T-130 — the association's own example: «لما يتم
                        // اختيار لا, تختفي مباشر جميع الخيارات التابعة لها».
                        // An empty table is that «لا», and it says so.
                        ->helperText(__('sanabel.support_source.empty_means_none'))
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_support_sources'))
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
                                ->suffix(Money::short()),

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
                        // T-130 — a repeater that opens with a blank row asks a
                        // question the family may not have an answer to, and then
                        // refuses the whole file over it. It opens empty: no row
                        // is «لا», and the delete button is how «لا» is said later.
                        ->defaultItems(0)
                        ->addActionLabel(__('sanabel.repeater.add_caregivers'))
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
                                ->suffix(Money::short()),

                            Forms\Components\Toggle::make('smokes')
                                ->label(__('sanabel.caregiver.smokes')),

                            Forms\Components\Toggle::make('neighbours_help')
                                ->label(__('sanabel.caregiver.neighbours_help'))
                                ->live(),

                            Forms\Components\TextInput::make('neighbours_support')
                                ->label(__('sanabel.caregiver.neighbours_support'))
                                ->numeric()->minValue(0)
                                ->suffix(Money::short())
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
                        ->suffix(Money::short()),

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
                ])
                    ->columns(2)
                    /*
                     | T-131 — «عند عدم اختيار الموافقة على سياسة الخصوصية
                     | واطلعت على سياسة الخصوصية لا يجب ان تنتقل الى الصفحة
                     | التالية لعدم الموافقة وتظهر خطأ», 27 October.
                     |
                     | The model has refused a file leaving draft without both
                     | answers since T-74, which is the hard stop. But it is a
                     | refusal at the end, and this page is where the two
                     | questions are asked: a researcher who walked past them
                     | found out at the very last screen. So the page does not
                     | hand over to the next one until they are answered, and
                     | says which of the two is missing.
                     |
                     | A validation rule on the two toggles would have done it
                     | at the price of the thing T-130 just fixed: a file whose
                     | visit has not happened yet could no longer be saved as a
                     | draft at all. This refuses the step, not the save.
                     */
                    ->afterValidation(function (Forms\Get $get) {
                        if ($get('privacy_policy_seen') === true && $get('privacy_consent') === true) {
                            return;
                        }

                        Notification::make()
                            ->title($get('privacy_policy_seen') !== true
                                ? __('sanabel.beneficiary.privacy_needs_seen_first')
                                : __('sanabel.beneficiary.privacy_consent_required'))
                            ->danger()
                            ->persistent()
                            ->send();

                        throw new Halt;
                    }),

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
                        ->suffix(Money::short()),

                    Forms\Components\TextInput::make('documented_debt')
                        ->label(__('sanabel.beneficiary.documented_debt'))
                        ->helperText(__('sanabel.beneficiary.debt_help'))
                        ->numeric()->minValue(0)->default(0)
                        ->suffix(Money::short()),

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
            ])->columnSpanFull()
                /*
                 | T-146 — the wizard is walked, not jumped.
                 |
                 | It was `skippable()`, and Filament runs no validation at all
                 | on a skippable wizard's «التالي» — it does not even call the
                 | step's own afterValidation. So T-131's refusal to hand the
                 | consents page over was unreachable from the screen: a
                 | screenshot of that page, pressed with both answers still
                 | off, walked straight through to the next one.
                 |
                 | The cost of walking it is the header: a step is reached by
                 | «التالي» rather than by clicking its number. The save is not
                 | affected — «إضافة» and «حفظ» sit under the form on every
                 | step, which is what keeps T-130's draft, filled on the first
                 | page and saved there, exactly as it was.
                 */
                /*
                 | T-177 — and a reader is never stopped by it.
                 |
                 | The association pressed «التالي» on the read-only screen and
                 | was answered «حقل الرقم الوطني مطلوب» — a required field,
                 | refusing to let them past a page they could not type on
                 | anyway. Validation is there to protect what is saved, and
                 | this screen saves nothing, so on it the wizard is walked
                 | freely and every step is one click away.
                 */
                ->skippable(fn (string $operation): bool => $operation === 'view'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             | T-132 — «ضمن صفحة ملفات المستفيدين عالج الظهور العرضي للصفحة كي
             | تظهر تكون الحاجة لعمل horizontal scrolling», 27 October.
             |
             | Eleven columns, seven of them always drawn, on a right-to-left
             | page: the status and the review date fell off the end and the
             | reader had to drag the table sideways to see whether a file was
             | approved. The answer is not a wider page but fewer columns,
             | without losing anything the list holds — so what belonged
             | together is drawn together, under the value it qualifies:
             |
             |   · the old system's number under the file number;
             |   · the place under the family name;
             |   · the review date under the status, in red when it is past.
             |
             | Four columns and two badges are left, which fits a laptop and a
             | telephone, and every column that was taken out is still one
             | click away under «الأعمدة».
             */
            ->columns([
                Tables\Columns\TextColumn::make('file_number')
                    ->label(__('sanabel.beneficiary.file_number'))
                    ->searchable()->sortable()
                    ->description(fn (Beneficiary $record) => $record->legacy_file_no
                        ? __('sanabel.beneficiary.legacy_file_no').': '.$record->legacy_file_no
                        : null),
                // T-65 — a file whose personal data has been destroyed, and
                // one with a request standing against it.
                /*
                 | T-163 — a file whose story is written and not yet signed.
                 | Nothing on this list used to say so, so nobody signed it.
                 */
                Tables\Columns\TextColumn::make('story_approved_at')
                    ->label(__('sanabel.beneficiary.story_awaiting'))
                    ->badge()->color('warning')
                    ->state(fn (Beneficiary $record) => filled($record->story_ar)
                        && $record->story_approved_at === null
                            ? __('sanabel.beneficiary.story_awaiting')
                            : null)
                    ->placeholder('')
                    ->toggleable(),
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
                    ->toggleable(isToggledHiddenByDefault: true),
                /*
                 | T-157 — «بصفحة مدير المنطقة ما نحتاج اظهار الكنية ممكن فقط
                 | الاسم الاول», 29 October.
                 |
                 | A supervisor reads this list to see how their area is
                 | moving, not to know which family is which: the file number
                 | names the file and the area names the place. So the column
                 | is the first name for them, and the surname is not on the
                 | screen and not searchable from it — a name that cannot be
                 | typed into a box cannot be fished out of one.
                 */
                self::nameColumn(),
                Tables\Columns\TextColumn::make('region.name_ar')
                    ->label(__('sanabel.beneficiary.region'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('support_type')
                    ->label(__('sanabel.beneficiary.support_type'))
                    ->formatStateUsing(fn (string $state) => __('sanabel.masked.need_type.'.$state))
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    })
                    // The review date under the status it decides, red when it
                    // has passed — the column it replaces said the same thing
                    // in the same colour, two columns further along.
                    ->description(function (Beneficiary $record) {
                        $due = $record->next_assessment_due_at;

                        if (! $due) {
                            return null;
                        }

                        $line = __('sanabel.beneficiary.next_assessment').': '.$due->format('Y-m-d');

                        return $due->isPast()
                            ? new HtmlString('<span class="text-danger-600 dark:text-danger-400">'.e($line).'</span>')
                            : $line;
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
                    ->sortable()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null)
                    // Drawn under the status by default; still its own column
                    // for whoever wants to sort the list by it.
                    ->toggleable(isToggledHiddenByDefault: true),
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

                /*
                 | T-163 — «القصة غير ظاهرة, لازم تكون ظاهرة بالصفحة»,
                 | 30 October.
                 |
                 | It was written and never signed. A story reaches the open
                 | internet only once an account that may approve a file puts
                 | its name to it (T-106), and that is the one guard between a
                 | paragraph about a household and a stranger reading it — so
                 | it stays. What was missing was any way to see which files
                 | were waiting: the button is inside the file, and nothing on
                 | the list said which files had one.
                 */
                Tables\Filters\Filter::make('story_awaiting')
                    ->label(__('sanabel.beneficiary.story_awaiting'))
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('story_ar')
                        ->whereNull('story_approved_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                /*
                 | T-163 — and signing them one at a time, through ten screens,
                 | is why nine of them were still waiting. The signature is the
                 | same one the button inside the file writes, and it is the
                 | same authority: `approve_case`, and the policy on each file.
                 */
                Tables\Actions\BulkAction::make('approveStories')
                    ->label(__('sanabel.actions.approve_stories'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('sanabel.actions.approve_stories_help'))
                    ->visible(fn () => auth()->user()?->can_('approve_case'))
                    ->action(function (Collection $records) {
                        $signed = $records
                            ->filter(fn (Beneficiary $record) => filled($record->story_ar)
                                && $record->story_approved_at === null
                                // The authority that signs a story is the one
                                // that approves the file, not the one that
                                // edits it: the executive director approves
                                // and does not edit, which is the whole shape
                                // of these two permissions.
                                && auth()->user()->can('approve', $record))
                            ->each(fn (Beneficiary $record) => $record->forceFill([
                                'story_approved_by' => auth()->id(),
                                'story_approved_at' => now(),
                            ])->save());

                        Notification::make()
                            ->title(__('sanabel.actions.stories_approved', ['count' => $signed->count()]))
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
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

    /**
     * T-128 — whose spouse is being asked about.
     *
     * A widow is asked about her husband and a married man about his wife,
     * and the file has the head's own gender to read it from. Where the
     * gender has not been answered yet the neutral pair is used rather than
     * a guess.
     */
    public static function spouseLabel(?string $gender): string
    {
        return match ($gender) {
            'female' => __('sanabel.beneficiary.spouse_husband'),
            'male' => __('sanabel.beneficiary.spouse_wife'),
            default => __('sanabel.beneficiary.spouse_either'),
        };
    }

    /**
     * T-152 — the work question, worded for whoever is being asked.
     *
     * It is asked of every marital status and always was; what it was not
     * doing was following the head's gender, the way every other word on this
     * form has since T-121.
     */
    public static function workLabel(?string $gender): string
    {
        return $gender === 'female'
            ? __('sanabel.beneficiary.work_status_f')
            : __('sanabel.beneficiary.work_status');
    }

    /**
     * T-172 — and the spouse's own work question, worded the same way.
     *
     * The head's gender decides it, as it decides every other word in this
     * block: a woman is asked about her husband, a man about his wife.
     */
    public static function spouseWorkLabel(?string $gender): string
    {
        return match ($gender) {
            'female' => __('sanabel.beneficiary.spouse_work_status_husband'),
            'male' => __('sanabel.beneficiary.spouse_work_status_wife'),
            default => __('sanabel.beneficiary.spouse_work_status_either'),
        };
    }

    /**
     * T-175 — a name the file already holds, put in the box that shows it.
     *
     * Only when the file holds one: a half-filled head leaves the box empty
     * rather than blanking a name somebody typed into it before the head was
     * finished.
     */
    private static function fillInheritedName(Forms\Components\TextInput $component, ?string $name): void
    {
        if (filled($name)) {
            $component->state($name);
        }
    }

    /**
     * T-175 — the head was renamed, so every child's row is renamed with them.
     *
     * Called from the four boxes a child's row reads: the head's own name, the
     * family name, the head's gender and the spouse they named. Only the rows
     * that inherit are touched; a brother or a grandmother keeps whatever was
     * typed into them.
     *
     * Reads the repeater's state rather than the record, because the names it
     * is copying are the ones on the screen and may not be saved yet — which
     * is the whole point of doing it here.
     */
    private static function refreshInheritedNames(Forms\Get $get, Forms\Set $set): void
    {
        $members = $get('members');

        if (! is_array($members) || $members === []) {
            return;
        }

        $gender = $get('gender');
        $first = $get('first_name');
        $spouse = $get('spouse_name_ar');
        $family = $get('family_name');

        foreach ($members as $key => $member) {
            $relation = $member['relation'] ?? null;

            if (! FamilyNames::inheritsFromHead($relation)) {
                continue;
            }

            $members[$key]['family_name_ar'] = FamilyNames::surnameFor($relation, $family);
            $members[$key]['father_name_ar'] = FamilyNames::fatherFor($relation, $gender, $first, $spouse);
            $members[$key]['mother_name_ar'] = FamilyNames::motherFor($relation, $gender, $first, $spouse);
        }

        $set('members', $members);
    }

    /**
     * T-175 — the father or the mother of a child in this household.
     *
     * Read from the head's row: the head is one of the two parents and the
     * spouse they named is the other, and which is which follows the head's
     * own gender. `$relation` is passed in from the kinship handler, where the
     * new value is not yet in `$get`.
     */
    private static function parentName(Forms\Get $get, string $which, ?string $relation = null): ?string
    {
        $relation ??= $get('relation');

        $arguments = [
            $relation,
            $get('../../gender'),
            $get('../../first_name'),
            $get('../../spouse_name_ar'),
        ];

        return $which === 'father'
            ? FamilyNames::fatherFor(...$arguments)
            : FamilyNames::motherFor(...$arguments);
    }

    public static function spouseNameLabel(?string $gender): string
    {
        return match ($gender) {
            'female' => __('sanabel.beneficiary.spouse_husband_name'),
            'male' => __('sanabel.beneficiary.spouse_wife_name'),
            default => __('sanabel.beneficiary.spouse_either_name'),
        };
    }

    /**
     * T-153 — the rule that reads one member's year against the head's.
     *
     * Both boxes carry it, because the date fills the year and either may be
     * the one the researcher typed into.
     */
    public static function ageAgainstHead(Forms\Get $get): \Closure
    {
        return function ($attribute, $value, $fail) use ($get) {
            $relation = $get('relation');

            $memberYear = self::yearOf($get('birth_date')) ?? ((int) $get('birth_year') ?: null);
            $headYear = self::yearOf($get('../../birth_date'));

            if (FamilyAges::birthYearFits($relation, $memberYear, $headYear)) {
                return;
            }

            $fail(in_array($relation, FamilyAges::BORN_AFTER_HEAD, true)
                ? __('sanabel.cases.child_born_before_head')
                : __('sanabel.cases.parent_born_after_head'));
        };
    }

    /** The year in a date, or null where there is no date. */
    private static function yearOf(mixed $date): ?int
    {
        return filled($date) ? (int) Carbon::parse($date)->year : null;
    }

    /**
     * T-154 — whether anybody on this file is said to be unable to work.
     *
     * Either of the two ways the form can say it: a member whose status is
     * «غير قادر على العمل», or one carrying the documented-condition flag.
     */
    public static function claimsUnableToWork(Forms\Get $get): bool
    {
        foreach ((array) $get('members') as $member) {
            if (($member['occupation'] ?? null) === 'unable'
                || ($member['has_documented_condition'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * T-156 — what the yes/no question opens with.
     *
     * A file that already carries rows has answered it; one being written for
     * the first time has not, and is asked. A saved file with an empty table
     * is «لا», which is what T-130 decided an empty table means.
     */
    public static function gateAnswer(?Beneficiary $record, string $relation): ?string
    {
        if (! $record?->exists) {
            return null;
        }

        return $record->{$relation}()->exists() ? 'yes' : 'no';
    }

    /**
     * T-157 — the name column, as much of it as the account needs.
     *
     * An area supervisor is shown the first name alone. Everybody else who
     * can open this list works the file itself — the researcher who visits,
     * the case officer who assesses, the director who approves — and reads
     * the family's name.
     */
    public static function nameColumn(): Tables\Columns\TextColumn
    {
        if (auth()->user()?->role?->key === 'area_supervisor') {
            return Tables\Columns\TextColumn::make('first_name')
                ->label(__('sanabel.beneficiary.first_name'))
                ->description(fn (Beneficiary $record) => $record->region?->name_ar);
        }

        return Tables\Columns\TextColumn::make('family_name')
            ->label(__('sanabel.beneficiary.family_name'))
            ->searchable()
            // The place the file is in, under the family it belongs to.
            ->description(fn (Beneficiary $record) => $record->region?->name_ar);
    }

    /**
     * T-159 — who last wrote to this file, in what capacity, and when.
     *
     * Read from the audit log rather than from a column, so it cannot say
     * anything the log does not: there is no field to set and nothing to
     * forget to set.
     */
    public static function lastEditLine(?Beneficiary $record): ?string
    {
        if (! $record?->exists) {
            return null;
        }

        $entry = $record->auditEntries()->with('actor')->latest('id')->first();

        if (! $entry) {
            return null;
        }

        if (! $entry->actor_id) {
            return __('sanabel.beneficiary.last_edit_system', [
                'action' => __('sanabel.audit.actions.'.$entry->action),
                'at' => $entry->created_at->translatedFormat('Y-m-d H:i'),
            ]);
        }

        return __('sanabel.beneficiary.last_edit_line', [
            'action' => __('sanabel.audit.actions.'.$entry->action),
            'role' => AuditLogResource::roleNames()[$entry->actor_role] ?? __('sanabel.audit.system'),
            'name' => $entry->actor?->name ?? __('sanabel.audit.system'),
            'at' => $entry->created_at->translatedFormat('Y-m-d H:i'),
        ]);
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

    /**
     * The class is always derived. The two dependency flags are derived only
     * where the screen did not answer them.
     *
     * T-129 — «عند معال او عاجز عن العمل يجب ان يكون قابلة للتعديل». The
     * rules fill both as the row is typed and refill them whenever the age or
     * the status changes, so what arrives here is the rules' answer unless
     * somebody corrected it. A row written by an import or the offline sync
     * sends neither, and is derived as before.
     */
    private static function deriveMemberFlags(array $data): array
    {
        $age = (int) date('Y') - (int) ($data['birth_year'] ?? date('Y'));
        $isStudent = ($data['occupation'] ?? null) === 'student' || (bool) ($data['is_student'] ?? false);

        $data['person_class'] = DependencyRules::personClass($age, $data['relation'] ?? null);

        $data['dependent'] = array_key_exists('dependent', $data) && $data['dependent'] !== null
            ? (bool) $data['dependent']
            : DependencyRules::isDependent($age, $isStudent, false);

        $data['unable_to_earn'] = array_key_exists('unable_to_earn', $data) && $data['unable_to_earn'] !== null
            ? (bool) $data['unable_to_earn']
            : DependencyRules::isUnableToEarn((bool) ($data['has_documented_condition'] ?? false));

        return $data;
    }

    /**
     * T-130 — «عند اضافة ملف المستفيد ما يظهر الملفات الجديدة المحفوظة»,
     * 27 October.
     *
     * One cause was a blank repeater row refusing the save on a step nobody
     * was looking at. The other was this: the place selects offered every
     * area and every locality in the governorate, while the region scope
     * shows a delegate only the locality they are attached to. File a family
     * in the next village along and it saved, and then vanished — from the
     * list, from their dashboard, from everything they can open.
     *
     * So a scoped account is offered the places it can still see afterwards,
     * and an unscoped one is offered them all.
     *
     * @return array<int,string>
     */
    private static function areaOptions(): array
    {
        $query = Region::where('is_active', true)->where('type', 'area');

        if ($own = self::scopedRegion()) {
            $area = $own->ancestorOfType('area');

            $query->whereIn('id', $area ? [$area->id] : Region::descendantIds($own->id));
        }

        return $query->orderBy('name_ar')->pluck('name_ar', 'id')->all();
    }

    /** @return array<int,string> */
    private static function localityOptions(mixed $areaId): array
    {
        $query = Region::where('is_active', true)->where('parent_id', $areaId);

        if ($own = self::scopedRegion()) {
            $query->whereIn('id', Region::descendantIds($own->id));
        }

        return $query->orderBy('name_ar')->pluck('name_ar', 'id')->all();
    }

    /** The region an account is tied to, when its role is tied to one at all. */
    private static function scopedRegion(): ?Region
    {
        $user = auth()->user();

        return in_array($user?->role?->key, RegionScope::SCOPED_ROLES, true) && $user->region_id
            ? Region::withoutGlobalScope(RegionScope::class)->find($user->region_id)
            : null;
    }

    /**
     * T-129 — the gender a kinship states, or null when it states none.
     *
     * «ابنة» with «ذكر» beside it was a row the form took. A daughter, a
     * mother, a sister and a wife are female; a son, a father and a brother
     * are male. The head of the household and «أخرى» say nothing about it.
     */
    public const RELATION_GENDER = [
        'spouse' => 'female',
        'daughter' => 'female',
        'mother' => 'female',
        'sister' => 'female',
        'son' => 'male',
        'father' => 'male',
        'brother' => 'male',
    ];

    public static function genderOfRelation(?string $relation): ?string
    {
        return self::RELATION_GENDER[$relation] ?? null;
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
