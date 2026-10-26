<?php

use App\Filament\Resources\BeneficiaryResource\Pages\CreateBeneficiary;
use App\Filament\Resources\BeneficiaryResource\Pages\ListBeneficiaries;
use App\Models\Beneficiary;
use App\Models\Region;
use Filament\Facades\Filament;

/*
 | T-130 — «عند اضافة ملف المستفيد ما يظهر الملفات الجديدة المحفوظة ولا يظهر
 | اي رسالة ان تم الاضافة, هل الامر يتعلق بالصلاحيات او مشكلة في العملية؟»
 | and «لما يتم اختيار لا, تختفي مباشر جميع الخيارات التابعة لها … وزر الحذف
 | يعبر عن لا او اخفاء», 27 October.
 |
 | Two causes, both in this test. The form opened every table with one blank
 | row, and those rows have required fields: a researcher who filled the
 | family's own page and pressed «إنشاء» was refused by fifteen errors on
 | steps they were not looking at, and nothing was saved. And the place
 | selects offered the whole governorate while the region scope shows a
 | delegate one locality, so a file saved into the next village along
 | disappeared the moment it was written.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

/** The identity page of the form, which is all a researcher must fill. */
function identityOnly(Region $locality): array
{
    return [
        'first_name' => 'أحمد',
        'father_name' => 'محمد',
        'family_name' => 'العلي',
        'national_id_encrypted' => '12345678901',
        'gender' => 'male',
        'marital_status' => 'married',
        // T-152 — a file that is not single names the spouse.
        'spouse_name_ar' => 'سعاد',
        'region_area_id' => $locality->parent_id,
        'region_id' => $locality->id,
        'support_type' => array_key_first(__('sanabel.masked.need_type')),
    ];
}

it('saves a file that answers the family page and nothing else', function () {
    $village = placeIn($this->region, 'village');
    $delegate = userWithRole('delegate', ['region_id' => $village->id]);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm(identityOnly($village))
        ->call('create')
        ->assertHasNoFormErrors()
        // The message the association said never came.
        ->assertNotified();

    $case = Beneficiary::withoutGlobalScopes()->latest('id')->first();

    expect($case)->not->toBeNull()
        ->and($case->first_name)->toBe('أحمد')
        ->and($case->created_by)->toBe($delegate->id)
        ->and($case->status)->toBe('draft')
        // Nothing was invented to fill the tables nobody answered.
        ->and($case->members)->toHaveCount(0)
        ->and($case->incomes)->toHaveCount(0)
        ->and($case->assets)->toHaveCount(0)
        ->and($case->debts)->toHaveCount(0);
});

it('leaves the saved file where the delegate who saved it can read it', function () {
    $village = placeIn($this->region, 'village');
    $delegate = userWithRole('delegate', ['region_id' => $village->id]);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm(identityOnly($village))
        ->call('create')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->latest('id')->first();

    Livewire\Livewire::actingAs($delegate)
        ->test(ListBeneficiaries::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$case]);
});

it('offers a delegate no locality they would lose the file in', function () {
    $village = placeIn($this->region, 'village');
    $area = $village->parent;

    $elsewhere = Region::create([
        'name_ar' => 'قرية أخرى', 'type' => 'village',
        'parent_id' => $area->id, 'is_active' => true,
    ]);

    $otherArea = Region::create([
        'name_ar' => 'منطقة أخرى', 'type' => 'area',
        'parent_id' => $this->region->id, 'is_active' => true,
    ]);

    $delegate = userWithRole('delegate', ['region_id' => $village->id]);

    $page = Livewire\Livewire::actingAs($delegate)->test(CreateBeneficiary::class);

    $areas = $page->instance()->form->getComponent('data.region_area_id')->getOptions();
    expect(array_keys($areas))->toBe([$area->id]);

    $page->fillForm(['region_area_id' => $area->id]);

    $localities = $page->instance()->form->getComponent('data.region_id')->getOptions();

    expect(array_keys($localities))->toBe([$village->id])
        ->and(array_keys($localities))->not->toContain($elsewhere->id)
        ->and(array_keys($areas))->not->toContain($otherArea->id);
})->group('AUTH-01');

it('still offers the whole governorate to an account that reads all of it', function () {
    $village = placeIn($this->region, 'village');
    $area = $village->parent;

    Region::create([
        'name_ar' => 'منطقة ثالثة', 'type' => 'area',
        'parent_id' => $this->region->id, 'is_active' => true,
    ]);

    $page = Livewire\Livewire::actingAs(userWithRole('case_officer'))->test(CreateBeneficiary::class);

    $areas = $page->instance()->form->getComponent('data.region_area_id')->getOptions();

    expect(count($areas))->toBe(2)
        ->and(array_keys($areas))->toContain($area->id);
});

it('says that an empty table means «no»', function () {
    // Their own example: «الدعم من جهة اخرى».
    expect(__('sanabel.support_source.empty_means_none'))->toContain('فارغًا')
        ->and(__('sanabel.repeater.add_support_sources'))->toBe('أضف جهة دعم');
});
