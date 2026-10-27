<?php

use App\Models\HealthRecord;
use App\Models\Setting;

/*
 | T-164 — «نوع الأدوية» على صفحة الحالة.
 |
 | Both were asked for on 29 October and held back: both were free text the
 | researcher wrote for the committee, and a sentence may carry a neighbour's
 | name, an address or a doctor's. On 30 October the association chose to ask
 | them as lists instead — a grade for the furnishing, a set of kinds for the
 | medicine. The sentences stay exactly where they were.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

it('publishes the kinds of medicine, and never the prescription', function () {
    $case = publishedCase($this->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 50,
        'medicines_ar' => 'إنسولين لانتوس ٢٠ وحدة',
        'medicine_kinds' => ['chronic', 'imported'],
    ]);

    $masked = maskedOf($case);

    expect($masked['medicine_kinds'])->toBe([
        __('sanabel.medicine_kinds.chronic'),
        __('sanabel.medicine_kinds.imported'),
    ])->and(json_encode($masked, JSON_UNESCAPED_UNICODE))->not->toContain('لانتوس');

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.medicine_kinds.chronic'))
        ->assertDontSee('لانتوس');
});

it('says each kind once however many records carry it', function () {
    $case = publishedCase($this->region);

    foreach ([['chronic', 'painkiller'], ['chronic']] as $kinds) {
        HealthRecord::factory()->create([
            'beneficiary_id' => $case->id,
            'severity_band' => 25,
            'medicine_kinds' => $kinds,
        ]);
    }

    expect(maskedOf($case)['medicine_kinds'])->toHaveCount(2);
});

it('ignores a kind that is not on the association’s list', function () {
    $case = publishedCase($this->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 25,
        'medicine_kinds' => ['chronic', 'something_nobody_added'],
    ]);

    expect(maskedOf($case)['medicine_kinds'])->toBe([__('sanabel.medicine_kinds.chronic')]);
});

it('closes both with the switch over the block they belong to', function () {
    $case = publishedCase($this->region);

    HealthRecord::factory()->create([
        'beneficiary_id' => $case->id,
        'severity_band' => 25,
        'medicine_kinds' => ['chronic'],
    ]);

    Setting::put('show_health_condition', false);

    expect(maskedOf($case)['medicine_kinds'])->toBe([]);
});
