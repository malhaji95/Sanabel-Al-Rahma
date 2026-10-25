<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\Region;

use function Pest\Livewire\livewire;

/*
 | T-121 — the association walked the delegate's registration screen on
 | 25 October and wrote down seven things. Six are what the form takes without
 | complaint and should not, or asks for and should not, or asks in words that
 | do not fit the person being asked. The seventh is a field that went missing.
 |
 | They are here in the order they sent them.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->officer);
});

function openCase(): Beneficiary
{
    return familyOf(test()->region, adults: 1, children: 1);
}

it('holds the identity number to eleven digits, and refuses ten or twelve', function () {
    $case = openCase();

    foreach (['1234567890', '123456789012'] as $wrong) {
        livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
            ->fillForm(['national_id_encrypted' => $wrong])
            ->call('save')
            ->assertHasFormErrors(['national_id_encrypted']);
    }

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['national_id_encrypted' => '01020304050'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->national_id_encrypted)
        ->toBe('01020304050');
});

it('reads an identity number typed on an Arabic keyboard as the digits it is', function () {
    // A researcher's phone sends ٠١٠٢٠٣٠٤٠٥٠, and a form that refuses it
    // reads as broken rather than as careful.
    $case = openCase();

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['national_id_encrypted' => '٠١٠٢٠٣٠٤٠٥٠'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->national_id_encrypted)
        ->toBe('01020304050');
});

it('asks for the area first and offers only the places inside it', function () {
    /*
     | «عند ادخال المنطقة موجود القرى كذالك, نحتاج فقط أظهار المنطقة والقرى
     | موجود لوحدها». One list held the governorate, five areas and every
     | locality under them.
     */
    $otherArea = Region::create([
        'name_ar' => 'منطقة أخرى',
        'type' => 'area',
        'parent_id' => $this->region->parent_id,
        'is_active' => true,
    ]);
    $elsewhere = Region::create([
        'name_ar' => 'قرية بعيدة',
        'type' => 'village',
        'parent_id' => $otherArea->id,
        'is_active' => true,
    ]);

    $case = openCase();
    $village = $case->region;

    $form = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()]);

    // Two questions on the screen now, each with its own name.
    expect($form->html())->toContain(__('sanabel.beneficiary.region_area'))
        ->and($form->html())->toContain(__('sanabel.beneficiary.region'));

    // The area is read back from the file rather than asked for again.
    expect($form->get('data.region_area_id'))->toBe($village->parent_id);

    // Choosing another area lets go of the locality under the old one, so a
    // file can never hold a village that is not in the area beside it.
    $form->set('data.region_area_id', $otherArea->id);

    expect($form->get('data.region_id'))->toBeNull();

    // And what is saved is still the locality itself, because that is what a
    // delegate is attached to and what the reference amounts resolve from.
    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['region_id' => $village->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = Beneficiary::withoutGlobalScopes()->find($case->getKey());

    expect($saved->region->type)->not->toBe('area')
        ->and($saved->region_id)->not->toBe($elsewhere->id);
});

it('asks for the location with a button rather than two decimal numbers', function () {
    // «خط العرض وخط الطول مو واضح معنا وما بيدخل». The values still save; what
    // went is the asking anyone to type them.
    $case = openCase();

    $html = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])->html();

    expect($html)->toContain(__('sanabel.beneficiary.location'))
        // The button that asks the device, rather than a box to type into.
        // Its own words are written through Alpine, so what is matched here
        // is the call and not the label.
        ->and($html)->toContain('locate()')
        // The two labelled number boxes are gone from the screen.
        ->and($html)->not->toContain(__('sanabel.beneficiary.latitude'));

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['latitude' => 32.6189, 'longitude' => 36.1021])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) Beneficiary::withoutGlobalScopes()->find($case->getKey())->latitude)
        ->toBe(32.6189);
});

it('holds every telephone number in the file to ten digits', function () {
    $case = openCase();

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['phone_encrypted' => '09331234'])
        ->call('save')
        ->assertHasFormErrors(['phone_encrypted']);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['phone_encrypted' => '0933 123 456'])
        ->call('save')
        ->assertHasNoFormErrors();

    // Written as it was typed, read as the number it is.
    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->phone_encrypted)
        ->toBe('0933123456');
});

it('writes the marital states to match the person being asked', function () {
    /*
     | «عند ادخال أنثى يظهر بيانات انه مطلق وهذا للرجل … يجب تأنيثهم». The
     | stored value does not change; the word on the screen does.
     */
    expect(BeneficiaryResource::maritalOptions('female'))
        ->toBe(__('sanabel.marital_status_f'))
        ->and(BeneficiaryResource::maritalOptions('female')['widowed'])->toBe('أرملة')
        ->and(BeneficiaryResource::maritalOptions('male')['widowed'])->toBe('أرمل')
        // The same four keys either way, so a file does not change meaning
        // when a gender is corrected.
        ->and(array_keys(BeneficiaryResource::maritalOptions('female')))
        ->toBe(array_keys(BeneficiaryResource::maritalOptions('male')));
});

it('no longer prints the note under the smoking question', function () {
    // «الملاحظة إلى تحت المدخن حذفها».
    $case = openCase();

    $html = livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])->html();

    expect($html)->toContain(__('sanabel.beneficiary.smokes'))
        ->and(trans()->has('sanabel.beneficiary.smokes_help'))->toBeFalse();
});

it('records what the work pays, where the work is asked about', function () {
    // «كان يوجد مبلغ الذي يأخذه من العمل, الأن غير مسجل, نحتاج خانة».
    $case = openCase();

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm([
            'birth_date' => '1984-03-02',
            'works' => true,
            'workplace_ar' => 'ورشة حدادة',
            'work_income' => 450_000,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->find($case->getKey())->work_income)
        ->toBe(450_000);
});

it('refuses a three-year-old who works, and one who has been widowed', function () {
    /*
     | «عند وضع عمري 3 سنوات تم وضع اني اعمل وأرمل وتم القبول. هذا الأشياء
     | مفروض يعطي انه في خطأ». Exactly that, twice.
     */
    $case = openCase();
    $threeYearsOld = now()->subYears(3)->toDateString();

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['birth_date' => $threeYearsOld, 'works' => true])
        ->call('save')
        ->assertHasFormErrors(['works']);

    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['birth_date' => $threeYearsOld, 'marital_status' => 'widowed'])
        ->call('save')
        ->assertHasFormErrors(['marital_status']);

    // The age itself is not refused: a household headed by a minor exists,
    // and what cannot be true is the work and the marriage.
    livewire(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['birth_date' => $threeYearsOld, 'works' => false, 'marital_status' => 'single'])
        ->call('save')
        ->assertHasNoFormErrors();
});
