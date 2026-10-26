<?php

use App\Http\Resources\MaskedCaseResource;
use App\Livewire\BrowseCases;
use App\Models\Asset;
use App\Models\Beneficiary;
use App\Models\Debt;
use App\Models\HealthRecord;
use App\Models\HouseholdMember;
use App\Models\Livestock;
use App\Models\Region;
use App\Models\Setting;

/*
 | T-101 — the fields the association named on 15 October, after being told
 | that «كامل البيانات» cannot be published and asking instead for a list.
 |
 | Two things are being tested here, and the second matters more than the
 | first. One: that each field they named reaches the donor, and that each
 | switch closes its own group again. Two: that the way it reaches them is a
 | count, a band or a label out of a list — and that not one sentence written
 | by a researcher for the committee comes with it. The free-text columns are
 | the leak, and they sit next to every field below: `description_ar` beside
 | the illness, `details_ar` beside the asset, `creditor_name_ar` beside the
 | debt, `furnishing_ar` beside the rooms, `notes_ar` beside the dependant.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A family with an answer to every question the association asked. */
function describedCase(Region $region): Beneficiary
{
    // No children from the factory: the one dependant below is this test's
    // own, so the row it checks is the row it wrote.
    $case = publishedCase($region, children: 0, attributes: [
        'gender' => 'female',
        'marital_status' => 'widowed',
        'smokes' => false,
        'documented_debt' => 480_000,
    ]);

    $case->housing->update([
        'housing_type' => 'rent',
        'habitable_rooms' => 2,
        'bathrooms' => 1,
        'has_kitchen' => true,
        'safety_band' => 50,
        'services_band' => 50,
        // The sentence beside the rooms. Written for the committee.
        'furnishing_ar' => 'فرش بسيط وبراد معطّل',
        'landlord_name_ar' => 'أبو المالك',
    ]);

    HealthRecord::create([
        'beneficiary_id' => $case->id,
        'condition' => 'diabetes',
        'severity_band' => 50,
        'economic_impact_band' => 25,
        'care_burden_band' => 25,
        'monthly_medical_cost' => 0,
        'currency' => config('sanabel.currency'),
        // The paragraph. It names a hospital and a doctor, which is why it is
        // written and why it is never published.
        'description_ar' => 'سكري من النوع الثاني، متابعة في المشفى الوطني مع الدكتور سامر',
    ]);

    Debt::create([
        'beneficiary_id' => $case->id,
        'creditor_name_ar' => 'أبو الدائن',
        'creditor_address_ar' => 'حي المنشية',
        'amount' => 480_000,
        'currency' => config('sanabel.currency'),
        'months' => 6,
    ]);

    Asset::create([
        'beneficiary_id' => $case->id,
        'kind' => 'motorcycle',
        'details_ar' => 'موتور قديم يستعمله الابن للعمل',
        'currency' => config('sanabel.currency'),
    ]);

    Livestock::create([
        'beneficiary_id' => $case->id,
        'kind' => 'sheep',
        'head_count' => 4,
        'income_kind' => 'family_use',
        'monthly_income' => 0,
        'currency' => config('sanabel.currency'),
        'is_stable' => false,
    ]);

    HouseholdMember::factory()->child()->create([
        'beneficiary_id' => $case->id,
        'name_ar' => 'بنت لها اسم',
        'birth_year' => (int) date('Y') - 9,
        'gender' => 'female',
        'dependent' => true,
        'is_student' => true,
        'is_orphan' => true,
        'notes_ar' => 'ملاحظة الباحث عن البنت',
    ]);

    return $case->refresh();
}

function describedPayload(Region $region): array
{
    return (new MaskedCaseResource(describedCase($region)))->toArray(request());
}

it('tells the donor each field the association named, and nothing outside the allowlist', function () {
    $payload = describedPayload($this->region);

    expect(array_diff(array_keys($payload), MaskedCaseResource::ALLOWED_KEYS))->toBeEmpty()
        ->and($payload['applicant_gender_label'])->toBe('أنثى')
        // Feminine, as they wrote it in their own list: «ارملة» not «أرمل».
        ->and($payload['marital_status_label'])->toBe('أرملة')
        ->and($payload['smokes'])->toBeFalse()
        ->and($payload['dependents_count'])->toBeGreaterThan(0)
        ->and($payload['housing_type_label'])->toBe('إيجار')
        ->and($payload['rooms'])->toBe(2)
        ->and($payload['bathrooms'])->toBe(1)
        ->and($payload['has_kitchen'])->toBeTrue()
        ->and($payload['home_safety_label'])->toBe('تدهور')
        ->and($payload['has_debt'])->toBeTrue()
        ->and($payload['debt_amount'])->toBe(480_000)
        ->and($payload['conditions'])->toContain('سكري')
        ->and($payload['has_vehicle'])->toBeTrue()
        ->and($payload['vehicles'])->toContain('دراجة نارية / موتور')
        ->and($payload['has_livestock'])->toBeTrue()
        ->and($payload['livestock'][0]['count'])->toBe(4);
})->group('AUTH-03');

it('publishes not one sentence a researcher wrote for the committee', function () {
    $json = json_encode(describedPayload($this->region), JSON_UNESCAPED_UNICODE);

    /*
     | Each of these sits in the same table as a field that is now published,
     | which is the whole risk of the change. The illness is published as
     | «سكري» and the paragraph behind it names a hospital and a doctor; the
     | moped is published as «دراجة نارية» and the line behind it says who
     | rides it to work.
     */
    foreach ([
        'المشفى الوطني',          // health_records.description_ar
        'الدكتور سامر',
        'فرش بسيط',               // housing.furnishing_ar
        'أبو المالك',             // housing.landlord_name_ar
        'أبو الدائن',             // debts.creditor_name_ar
        'حي المنشية',             // debts.creditor_address_ar
        'موتور قديم',             // assets.details_ar
        'بنت لها اسم',            // household_members.name_ar
        'ملاحظة الباحث',          // household_members.notes_ar
    ] as $written) {
        expect($json)->not->toContain($written);
    }
})->group('AUTH-03');

it('describes a dependant without a name, a birth year or an age in years', function () {
    $payload = describedPayload($this->region);

    expect($payload['dependents'])->not->toBeEmpty();

    foreach ($payload['dependents'] as $row) {
        expect(array_diff(array_keys($row), MaskedCaseResource::DEPENDENT_KEYS))->toBeEmpty();
    }

    // The row for the girl this test created, not whichever dependant the
    // family factory happens to put first.
    $girl = collect($payload['dependents'])
        ->first(fn (array $row) => $row['gender_label'] === 'أنثى' && $row['is_under_15']);

    expect($girl)->not->toBeNull()
        // The one question they asked about age, and the only one answered.
        ->and($girl['is_under_15'])->toBeTrue()
        // T-106 — «الصفة» is its own key now, out of the survey's own list.
        ->and($girl['occupation_label'])->toBe(__('sanabel.masked.is_student'))
        // «يتيم اذا كانت ارملة» — and this applicant is a widow.
        ->and($girl['is_orphan'])->toBeTrue();
})->group('AUTH-03');

it('does not call a child orphaned on a file where the father is alive and on it', function () {
    $case = describedCase($this->region);
    $case->update(['marital_status' => 'married', 'gender' => 'male']);

    $payload = (new MaskedCaseResource($case->refresh()))->toArray(request());

    expect($payload['marital_status_label'])->toBe('متزوج')
        ->and(collect($payload['dependents'])->pluck('is_orphan')->filter())->toBeEmpty();
})->group('AUTH-03');

it('closes each group again on the switch the association holds', function () {
    $open = describedPayload($this->region);

    expect($open['rooms'])->not->toBeNull()
        ->and($open['conditions'])->not->toBeEmpty();

    foreach ([
        'show_household_profile' => ['applicant_gender_label', 'marital_status_label', 'smokes', 'dependents_count'],
        'show_housing_detail' => ['housing_type_label', 'rooms', 'bathrooms', 'has_kitchen', 'home_safety_label'],
        'show_debt_amount' => ['has_debt', 'debt_amount'],
        'show_possessions' => ['has_vehicle', 'has_livestock', 'has_property'],
    ] as $switch => $keys) {
        Setting::put($switch, false);

        $closed = describedPayload($this->region);

        foreach ($keys as $key) {
            expect($closed[$key])->toBeNull();
        }

        Setting::put($switch, true);
    }

    // The two that empty a list rather than null a value.
    Setting::put('show_health_condition', false);
    expect(describedPayload($this->region)['conditions'])->toBeEmpty();

    Setting::put('show_dependents', false);
    expect(describedPayload($this->region)['dependents'])->toBeEmpty();
})->group('AUTH-03');

it('keeps the chronic-illness marker when the naming switch is closed', function () {
    // Closing the name does not close the fact. A donor is still told the
    // household carries a chronic illness, which is what the card said before
    // 15 October and what the ranking has always been able to justify.
    Setting::put('show_health_condition', false);

    $payload = describedPayload($this->region);

    expect($payload['has_chronic_illness'])->toBeTrue()
        ->and($payload['conditions'])->toBeEmpty();
})->group('AUTH-03');

it('leaves the village out of it, which is where the 15 October exchange started', function () {
    $payload = describedPayload($this->region);

    // The association's own correction: «كلمناك ما نحتاج تظهر، فقط المنطقة».
    // It was already true, and the nineteen fields added around it do not
    // make it any less necessary — they make it more.
    $case = describedCase($this->region);

    expect($payload['area_ar'])->toBe($case->region->ancestorOfType('area')?->name_ar
        ?? $case->region->ancestorOfType('governorate')?->name_ar)
        ->and($payload['area_ar'])->not->toBe($case->region->name_ar);
})->group('AUTH-03');

it('draws the household on the family page, and the researcher text on none of it', function () {
    $case = describedCase($this->region);

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    expect($body)->toContain(__('sanabel.masked.household'))
        ->and($body)->toContain(__('sanabel.masked.dependents'))
        ->and($body)->toContain('سكري')
        ->and($body)->toContain(__('sanabel.masked.under_15'))
        ->and($body)->toContain(__('sanabel.masked.orphan'))
        // The page is a rendered template rather than a payload, so it is
        // worth asking it the same question separately.
        ->and($body)->not->toContain('المشفى الوطني')
        ->and($body)->not->toContain('فرش بسيط')
        ->and($body)->not->toContain('موتور قديم')
        ->and($body)->not->toContain('بنت لها اسم');
})->group('AUTH-03');

it('empties the block from the page when the association closes every switch', function () {
    // Every switch over what a donor is shown, read from the defaults rather
    // than listed here: a switch added later is closed by this test too.
    foreach (array_keys(collect(config('sanabel.setting_defaults'))
        ->filter(fn ($default, $key) => is_bool($default) && str_starts_with($key, 'show_'))
        ->all()) as $switch) {
        Setting::put($switch, false);
    }

    $case = describedCase($this->region);

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    // Not an empty card with a heading over it: the section is not drawn.
    expect($body)->not->toContain(__('sanabel.masked.household'))
        ->and($body)->not->toContain('سكري');
})->group('AUTH-03');

/*
 | T-102 — three answers the association asked for on 15 October that the
 | page was not giving, after they read it on the live demonstration.
 */

it('says whether the illness is under continuing treatment', function () {
    $case = describedCase($this->region);
    $case->healthRecords()->update(['ongoing_treatment' => true]);

    $payload = (new MaskedCaseResource($case->refresh()))->toArray(request());

    expect($payload['ongoing_treatment'])->toBeTrue();

    Setting::put('show_health_condition', false);

    // Null rather than false: a closed switch is not an answer of «لا».
    expect((new MaskedCaseResource($case->refresh()))->toArray(request())['ongoing_treatment'])
        ->toBeNull();
})->group('AUTH-03');

it('answers the health question on a family with no illness, instead of saying nothing', function () {
    $case = publishedCase($this->region, children: 0);

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    // The row is drawn with «لا» in it. A question with no line at all reads
    // as a question nobody asked.
    expect($body)->toContain(__('sanabel.masked.conditions'))
        ->and($body)->toContain(__('sanabel.masked.ongoing_treatment'));
})->group('AUTH-03');

it('labels the family page as more information, not as a second donate button', function () {
    publishedCase($this->region);

    $browse = Livewire\Livewire::test(BrowseCases::class);

    $browse->assertSee(__('sanabel.public.more_info'));

    $home = $this->get(route('home'))->assertOk()->getContent();

    expect($home)->toContain(__('sanabel.public.more_info'));
})->group('AUTH-03');

/*
 | T-106 — «وضروري شرح»، 16 October, with the reason given beside it: «قدر ما
 | بكون فيه معلومات كافية وافية للمتبرع، قدر ما بنرغّب المتبرع إنه يدفع».
 |
 | One paragraph, written for the donor rather than for the committee, and
 | the only free text that ever leaves the association. The approval is the
 | whole of why it may be one, so most of what follows is about the
 | approval.
 */

it('shows no story until somebody has signed it off', function () {
    $case = describedCase($this->region);
    $case->update(['story_ar' => 'أسرة فقدت معيلها وتسكن بالإيجار.']);

    expect((new MaskedCaseResource($case->refresh()))->toArray(request())['story_ar'])
        ->toBeNull();

    $case->forceFill([
        'story_approved_by' => userWithRole('executive_director')->id,
        'story_approved_at' => now(),
    ])->save();

    expect((new MaskedCaseResource($case->refresh()))->toArray(request())['story_ar'])
        ->toBe('أسرة فقدت معيلها وتسكن بالإيجار.');
})->group('AUTH-03');

it('takes the signature off the moment the words change', function () {
    $case = describedCase($this->region);
    $case->update(['story_ar' => 'النص الذي اعتُمد.']);
    $case->forceFill([
        'story_approved_by' => userWithRole('executive_director')->id,
        'story_approved_at' => now(),
    ])->save();

    // An approval that survived a rewrite would be a signature under words
    // nobody read.
    $case->update(['story_ar' => 'نصٌّ آخر تمامًا، كُتب بعد الاعتماد.']);

    expect($case->refresh()->story_approved_at)->toBeNull()
        ->and($case->story_approved_by)->toBeNull()
        ->and((new MaskedCaseResource($case))->toArray(request())['story_ar'])->toBeNull();
})->group('AUTH-03');

it('publishes no story for an anonymised file, approved or not', function () {
    $case = describedCase($this->region);
    $case->update(['story_ar' => 'نصٌّ معتمد.']);
    $case->forceFill([
        'story_approved_by' => userWithRole('executive_director')->id,
        'story_approved_at' => now(),
        'anonymised_at' => now(),
    ])->save();

    expect($case->refresh()->approvedStory())->toBeNull();
})->group('AUTH-03');

it('closes every story at once on the association switch, and names the heading they chose', function () {
    $case = describedCase($this->region);
    $case->update(['story_ar' => 'نصٌّ معتمد.']);
    $case->forceFill([
        'story_approved_by' => userWithRole('executive_director')->id,
        'story_approved_at' => now(),
    ])->save();

    Setting::put('story_title', 'brief');

    $payload = (new MaskedCaseResource($case->refresh()))->toArray(request());

    expect($payload['story_title'])->toBe(__('sanabel.masked.story_titles.brief'));

    Setting::put('show_story', false);

    expect((new MaskedCaseResource($case->refresh()))->toArray(request())['story_ar'])->toBeNull();
})->group('AUTH-03');

it('tells the donor the relation, the standing and the illness of each dependant', function () {
    $case = describedCase($this->region);

    $case->members()->where('dependent', true)->update([
        'relation' => 'daughter',
        'occupation' => 'student',
        'marital_status' => 'single',
    ]);

    $row = collect((new MaskedCaseResource($case->refresh()))->toArray(request())['dependents'])->first();

    expect($row['relation_label'])->toBe(__('sanabel.relation.daughter'))
        ->and($row['occupation_label'])->toBe(__('sanabel.occupation.student'))
        ->and($row['marital_label'])->toBe(__('sanabel.marital_status_f.single'))
        // Still no name, no birth year, no age in years.
        ->and(array_diff(array_keys($row), MaskedCaseResource::DEPENDENT_KEYS))->toBeEmpty();
})->group('AUTH-03');

it('says a dependant is ill even where the association has not named the illness', function () {
    $case = describedCase($this->region);
    $member = $case->members()->where('dependent', true)->first();

    HealthRecord::create([
        'beneficiary_id' => $case->id,
        'member_id' => $member->id,
        'severity_band' => 75,
        'economic_impact_band' => 25,
        'care_burden_band' => 25,
        'monthly_medical_cost' => 0,
        'currency' => config('sanabel.currency'),
        // No `condition`: the illness is recorded and not named.
        'description_ar' => 'وصف داخلي',
    ]);

    $row = collect((new MaskedCaseResource($case->refresh()))->toArray(request())['dependents'])
        ->firstWhere('has_condition', true);

    expect($row)->not->toBeNull()
        ->and($row['conditions'])->toBeEmpty();
})->group('AUTH-03');

it('draws the story and the debt value on the family page', function () {
    $case = describedCase($this->region);
    $case->update(['story_ar' => 'الشرح الذي تكتبه الجمعية.']);
    $case->forceFill([
        'story_approved_by' => userWithRole('executive_director')->id,
        'story_approved_at' => now(),
    ])->save();

    $body = $this->get(route('opportunity', $case->file_number))->assertOk()->getContent();

    expect($body)->toContain('الشرح الذي تكتبه الجمعية.')
        ->and($body)->toContain(__('sanabel.masked.story_titles.hope'))
        // «قيمة الديون» rather than «ديون» once there is a value to name.
        ->and($body)->toContain(__('sanabel.masked.debt_amount_row'))
        ->and($body)->toContain(number_format(480_000));
})->group('AUTH-03');
