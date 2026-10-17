<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Services\CoverageService;
use App\Services\NeedEngine;

use function Pest\Livewire\livewire;

/*
 | T-55 — what the family owns and what they owe, on the screen.
 |
 | Five tables came in on 9 October with nowhere to type them. These go
 | through the panel the way a delegate does, and hold the two answers the
 | association settled on 11 October: the question on each asset, and the
 | month a payment from elsewhere answers.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    /*
     | T-105 — the editor here used to be the system administrator, on the
     | convenience that it held every key. It no longer holds the key to a
     | family file: on 16 October the association confined that account to
     | preparing the system. The screens under test are the case officer's,
     | and that is who opens them now.
     */
    $this->admin = userWithRole('case_officer', ['region_id' => $this->region->id]);
    passTwoFactor();
    $this->actingAs($this->admin);
    $this->case = familyOf($this->region, adults: 1, children: 1);
});

it('records an asset with every question answered on it', function () {
    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['assets' => [[
            'kind' => 'tractor',
            'necessity' => 'essential',
            'necessity_reason_ar' => 'يعمل عليه صاحبه',
            'estimated_value' => 40_000_000,
            'is_exploited' => true,
            'has_control' => true,
            'details_ar' => 'جرار قديم يعمل عليه بالأجرة',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $asset = $this->case->assets()->first();

    expect($asset->kind)->toBe('tractor')
        ->and($asset->necessity)->toBe('essential')
        ->and($asset->necessity_reason_ar)->toBe('يعمل عليه صاحبه')
        ->and($asset->estimated_value)->toBe(40_000_000)
        ->and($asset->is_exploited)->toBeTrue()
        ->and($asset->has_control)->toBeTrue()
        ->and($asset->isEssential())->toBeTrue()
        ->and($asset->isAssessed())->toBeTrue();
});

it('refuses an asset with no note on it', function () {
    // T-64 — the committee judges by hand, so its only material is what the
    // researcher wrote. An asset with no description is of no use to them.
    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['assets' => [[
            'kind' => 'land',
            'necessity' => 'surplus',
            'estimated_value' => 9_000_000,
            'details_ar' => null,
        ]]])
        ->call('save')
        ->assertHasFormErrors();

    expect($this->case->assets()->count())->toBe(0);
});

it('records a herd, and its steady income reaches the need calculation', function () {
    $before = app(NeedEngine::class)->compute($this->case);

    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['livestock' => [[
            'kind' => 'sheep',
            'kind_other_ar' => null,
            'head_count' => 12,
            'income_kind' => 'partial_income',
            'monthly_income' => 40_000,
            'is_stable' => true,
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $herd = $this->case->livestock()->first();
    $after = app(NeedEngine::class)->compute($this->case->fresh());

    expect($herd->head_count)->toBe(12)
        ->and($herd->is_stable)->toBeTrue()
        ->and($after['stable_income'])->toBe($before['stable_income'] + 40_000);
});

it('records a debt with its details and adds it to nothing', function () {
    $before = app(NeedEngine::class)->compute($this->case);

    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['debts' => [[
            'creditor_name_ar' => 'أبو خالد',
            'amount' => 400_000,
            'creditor_address_ar' => 'حي المنشية',
            'creditor_phone_encrypted' => '0955111222',
            'months' => 6,
            'has_due_date' => true,
            'due_date' => now()->addMonths(6)->toDateString(),
            'reason_ar' => 'ثمن أدوية',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $debt = $this->case->debts()->first();

    expect($debt->creditor_name_ar)->toBe('أبو خالد')
        ->and($debt->amount)->toBe(400_000)
        ->and($debt->creditor_phone_encrypted)->toBe('0955111222')
        ->and($debt->months)->toBe(6)
        // Its value never entered the monthly need, so it is neither added to
        // it nor taken off it (approved 11 October). The snapshot carries the
        // moment it was computed, so the figures are compared, not the array.
        ->and(array_intersect_key(
            app(NeedEngine::class)->compute($this->case->fresh()),
            array_flip(['monthly_need', 'stable_income', 'received', 'gap', 'f']),
        ))->toBe(array_intersect_key(
            $before,
            array_flip(['monthly_need', 'stable_income', 'received', 'gap', 'f']),
        ));
});

it('records a payment from elsewhere against the month it answers', function () {
    $month = app(CoverageService::class)->currentMonth();

    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['supportSources' => [[
            'name_ar' => 'جمعية البر',
            'kind' => 'one_off',
            'amount' => 30_000,
            'coverage_month' => $month->toDateString(),
            'paid_on' => $month->toDateString(),
            'notes_ar' => 'دفعة واحدة',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = $this->case->supportSources()->first();

    expect($row->name_ar)->toBe('جمعية البر')
        ->and($row->kind)->toBe('one_off')
        ->and($row->isPayment())->toBeTrue()
        ->and(app(CoverageService::class)->externalForMonth($this->case->fresh(), $month))
        ->toBe(30_000);
});

it('records whoever is keeping the household', function () {
    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['caregivers' => [[
            'name_ar' => 'أم محمد',
            'relation' => 'mother',
            'national_id_encrypted' => '01234567890',
            'income_source_ar' => 'معاش تقاعدي',
            'monthly_support' => 50_000,
            'smokes' => false,
            'neighbours_help' => true,
            'notes_ar' => 'تسكن معهم',
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $carer = $this->case->caregivers()->first();

    expect($carer->name_ar)->toBe('أم محمد')
        ->and($carer->relation)->toBe('mother')
        ->and($carer->national_id_encrypted)->toBe('01234567890')
        ->and($carer->monthly_support)->toBe(50_000)
        ->and($carer->neighbours_help)->toBeTrue();
});

it('opens the rows it already holds with the encrypted numbers on them', function () {
    $this->case->debts()->create([
        'creditor_name_ar' => 'أبو خالد',
        'amount' => 400_000,
        'creditor_phone_encrypted' => '0955111222',
    ]);
    $this->case->caregivers()->create([
        'name_ar' => 'أم محمد',
        'relation' => 'mother',
        'national_id_encrypted' => '01234567890',
    ]);

    $data = livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])->get('data');

    expect(array_values($data['debts'])[0]['creditor_phone_encrypted'])->toBe('0955111222')
        ->and(array_values($data['caregivers'])[0]['national_id_encrypted'])->toBe('01234567890');
});

it('keeps those numbers when an unrelated field is saved', function () {
    $debt = $this->case->debts()->create([
        'creditor_name_ar' => 'أبو خالد',
        'amount' => 400_000,
        'creditor_phone_encrypted' => '0955111222',
    ]);

    livewire(EditBeneficiary::class, ['record' => $this->case->getRouteKey()])
        ->fillForm(['family_name' => 'اسم معدَّل'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($debt->fresh()->creditor_phone_encrypted)->toBe('0955111222');
});
