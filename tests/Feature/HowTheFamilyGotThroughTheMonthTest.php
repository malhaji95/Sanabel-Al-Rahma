<?php

use App\Filament\Resources\BeneficiaryResource\Pages\CreateBeneficiary;
use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\NeedEngine;
use App\Services\ScoreService;
use Filament\Facades\Filament;

/*
 | T-299 — «نحتاج إضافة سؤال إلزامي في دراسة الأسرة: كيف قامت الأسرة بتغطية
 | مصاريف معيشتها خلال آخر ٣٠ يومًا؟», 12 November.
 |
 | «الهدف ليس اعتبار جميع هذه الموارد دخلًا, بل معرفة الواقع المالي للأسرة
 | والتحقق من صحة بياناتها. إذا كانت الأسرة تسجل دخلًا صفريًا, يجب توضيح
 | مصادر معيشتها الفعلية دون رفضها تلقائيًا.»
 |
 | Two halves, and the second is the one worth guarding: the question must be
 | answered, and the answer must change no figure anywhere.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('will not take a file that does not say how the family lived', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $place = placeIn($this->region, 'village');

    $this->actingAs($delegate);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm([
            ...gatesAnsweredNo(),
            'livelihood_means' => [],
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'family_name' => 'العلي',
            'national_id_encrypted' => '12345678901',
            'gender' => 'male',
            'birth_date' => now()->subYears(38)->toDateString(),
            'marital_status' => 'married',
            'spouse_name_ar' => 'سعاد',
            'spouse_national_id_encrypted' => '09876543210',
            'region_area_id' => $place->parent_id,
            'region_id' => $place->id,
            'support_type' => array_key_first(__('sanabel.masked.need_type')),
        ])
        ->call('create')
        ->assertHasFormErrors(['livelihood_means']);

    expect(Beneficiary::withoutGlobalScopes()->where('first_name', 'أحمد')->exists())->toBeFalse();
});

it('takes the file once it does, and keeps every answer given', function () {
    $delegate = userWithRole('delegate', ['region_id' => $this->region->id]);
    $place = placeIn($this->region, 'village');

    $this->actingAs($delegate);

    Livewire\Livewire::actingAs($delegate)
        ->test(CreateBeneficiary::class)
        ->fillForm([
            ...gatesAnsweredNo(),
            // A family that borrows also cuts back: more than one answer.
            'livelihood_means' => ['shop_credit', 'cutting_back', 'relatives_neighbours'],
            'first_name' => 'أحمد',
            'father_name' => 'محمد',
            'family_name' => 'العلي',
            'national_id_encrypted' => '12345678901',
            'gender' => 'male',
            'birth_date' => now()->subYears(38)->toDateString(),
            'marital_status' => 'married',
            'spouse_name_ar' => 'سعاد',
            'spouse_national_id_encrypted' => '09876543210',
            'region_area_id' => $place->parent_id,
            'region_id' => $place->id,
            'support_type' => array_key_first(__('sanabel.masked.need_type')),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $case = Beneficiary::withoutGlobalScopes()->where('first_name', 'أحمد')->first();

    expect($case->livelihood_means)->toBe(['shop_credit', 'cutting_back', 'relatives_neighbours']);
});

it('changes no figure anywhere, whatever the family answers', function () {
    /*
     | The promise the association asked for in as many words: «الهدف ليس
     | اعتبار جميع هذه الموارد دخلًا». A household living on debt and the
     | kindness of its neighbours is not thereby credited with an income, and
     | its need, its deficit and its place in the queue are what they were.
     */
    $case = familyOf($this->region, adults: 2, children: 2);
    $case->forceFill(['livelihood_means' => null])->save();

    $before = app(NeedEngine::class)->compute($case->fresh());
    $scoreBefore = app(ScoreService::class)->compute($case->fresh(), $before);

    $case->forceFill([
        'livelihood_means' => ['work', 'savings', 'selling_assets', 'associations', 'in_kind'],
        'livelihood_means_other_ar' => 'باعوا خاتمًا',
    ])->save();

    $after = app(NeedEngine::class)->compute($case->fresh());
    $scoreAfter = app(ScoreService::class)->compute($case->fresh(), $after);

    expect($after['monthly_need'])->toBe($before['monthly_need'])
        ->and($after['stable_income'])->toBe($before['stable_income'])
        ->and($after['gap'])->toBe($before['gap'])
        ->and($after['f'])->toBe($before['f'])
        ->and($scoreAfter['base_score'])->toBe($scoreBefore['base_score'])
        ->and($scoreAfter['factors'])->toBe($scoreBefore['factors']);
});

it('does not refuse a family for declaring no income at all', function () {
    /*
     | «إذا كانت الأسرة تسجل دخلًا صفريًا, يجب توضيح مصادر معيشتها الفعلية دون
     | رفضها تلقائيًا.» So the question is the obligation, not an income: a
     | file that answers «لا» to income and says how it lived is a complete
     | file, and the study it carries is computed like any other.
     */
    $case = familyOf($this->region, adults: 1, children: 3);
    $case->incomes()->forceDelete();
    $case->forceFill([
        'work_income' => null,
        'spouse_work_income' => null,
        'livelihood_means' => ['relatives_neighbours', 'cutting_back'],
    ])->save();

    $need = app(NeedEngine::class)->compute($case->fresh());

    expect($need['stable_income'])->toBe(0)
        ->and($need['monthly_need'])->toBeGreaterThan(0)
        ->and($case->fresh()->status)->not->toBe('rejected');
});

it('asks what the other way was, when «غير ذلك» is the answer', function () {
    $case = familyOf($this->region);
    $officer = userWithRole('case_officer', ['region_id' => $this->region->id]);
    $this->actingAs($officer);

    Livewire\Livewire::actingAs($officer)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->fillForm(['livelihood_means' => ['other'], 'livelihood_means_other_ar' => null])
        ->call('save')
        ->assertHasFormErrors(['livelihood_means_other_ar']);
});

it('lets the answer be corrected on an approved file, like the declared income', function () {
    /*
     | A family's account of how it lives changes, and correcting it must not
     | mean reopening the file. It goes through a change request, which is
     | where every other answer of this kind goes.
     */
    expect(ChangeRequest::CHANGEABLE_FIELDS)->toContain('livelihood_means')
        ->and(ChangeRequest::CHANGEABLE_FIELDS)->toContain('livelihood_means_other_ar');
});

it('offers every way the association listed, and no other', function () {
    expect(array_keys(__('sanabel.livelihood.means')))->toBe([
        'work', 'relatives_neighbours', 'associations', 'shop_credit',
        'savings', 'selling_assets', 'in_kind', 'cutting_back', 'other',
    ]);
});
