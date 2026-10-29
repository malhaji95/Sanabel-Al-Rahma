<?php

use App\Filament\Resources\BeneficiaryResource;
use App\Filament\Resources\BeneficiaryResource\Pages\CreateBeneficiary;
use App\Models\Beneficiary;
use App\Models\Media;
use App\Models\Region;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;

/*
 | T-152 — «الزوج لم يتم اضافته. لازم بس يسجل متزوجه او ارمله او مطلقه يكتب
 | اسم الزوج اجباري مع معلوماته … ولما يتم اختيار ارملة يتم اعطاء خيار ارفاق
 | شهادة وفاة ولما يتم اختيار مطلقة يظهر خيار ارفاق صك الطلاق … لما نختار
 | عزباء يظهر موضوع العمل نحتاج يظهر نفس الشيء عند الخيارات التانية», 29 Oct.
 |
 | The block has been on the form since T-128 and could be walked past, so a
 | file could say «أرملة» and name nobody. The name is required wherever the
 | block is shown. The identity number was left open here, because a husband
 | who died years ago may have left no paper behind; T-187 closed it for
 | «متزوجة» and «أرملة» on 29 October, and it stays open for «مطلقة» and
 | «منفصلة», where the paper is not the family's to produce.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A delegate and the village they work in. */
function researcherIn(Region $governorate): array
{
    $delegate = userWithRole('delegate', ['region_id' => $governorate->id]);
    $village = Region::withoutGlobalScopes()->find($delegate->region_id);

    return [$delegate, $village];
}

function fileForm(Region $village, array $overrides = []): array
{
    return array_merge([
        'file_number' => 'T152-'.fake()->unique()->numberBetween(1000, 9999),
        'national_id_encrypted' => (string) fake()->unique()->numerify('919########'),
        'first_name' => 'تجربة',
        'father_name' => 'أب',
        'family_name' => 'اختبار',
        'gender' => 'female',
        'region_id' => $village->id,
        'support_type' => 'monthly',
    ], gatesAnsweredNo(), $overrides);
}

it('refuses a widow’s file that names nobody', function () {
    [$delegate, $village] = researcherIn($this->region);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm(fileForm($village, ['marital_status' => 'widowed']))
        ->call('create')
        ->assertHasFormErrors(['spouse_name_ar']);
});

it('asks nobody who is single for a spouse', function () {
    [$delegate, $village] = researcherIn($this->region);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm(fileForm($village, ['marital_status' => 'single']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Beneficiary::withoutGlobalScopes()->latest('id')->first()->marital_status)->toBe('single');
});

it('takes the file once the husband is named, without asking a divorcée for his number', function () {
    [$delegate, $village] = researcherIn($this->region);

    // T-187 — «أرملة» is now asked for the number too. «مطلقة» is the one
    // left open, and it is the one this guard stands on.
    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm(fileForm($village, [
            'marital_status' => 'divorced',
            'spouse_name_ar' => 'أحمد بن علي',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->latest('id')->first();

    expect($case->spouse_name_ar)->toBe('أحمد بن علي')
        ->and($case->spouse_national_id_encrypted)->toBeNull();
});

it('files the widow’s paper as a death certificate and the divorcee’s as a deed', function () {
    [$delegate, $village] = researcherIn($this->region);

    foreach ([
        'widowed' => 'death_certificate',
        'divorced' => 'divorce_deed',
    ] as $status => $kind) {
        Livewire\Livewire::actingAs($delegate)
            ->test(CreateBeneficiary::class)
            ->fillForm(fileForm($village, [
                'marital_status' => $status,
                'spouse_name_ar' => 'أحمد بن علي',
                // T-187 — the widow's file carries the number now; the
                // divorcée's is taken with or without it.
                'spouse_national_id_encrypted' => '09876543210',
                'spouse_proof' => UploadedFile::fake()->image('paper.jpg'),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $case = Beneficiary::withoutGlobalScopes()->latest('id')->first();

        expect($case->documents()->pluck('kind')->all())->toBe([$kind]);
    }

    // And both kinds are ones the documents list already knows how to name.
    expect(Media::FAMILY_KINDS)->toContain('death_certificate')->toContain('divorce_deed');
});

it('asks about work whatever the marital status, in the head’s own words', function () {
    expect(BeneficiaryResource::workLabel('female'))->toBe(__('sanabel.beneficiary.work_status_f'))
        ->and(BeneficiaryResource::workLabel('male'))->toBe(__('sanabel.beneficiary.work_status'))
        ->and(BeneficiaryResource::workLabel(null))->toBe(__('sanabel.beneficiary.work_status'));
});
