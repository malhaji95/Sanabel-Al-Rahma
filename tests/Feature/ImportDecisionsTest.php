<?php

use App\Models\Beneficiary;
use App\Models\ChangeRequest;
use App\Services\CaseService;
use App\Services\NeedEngine;

/*
 | T-63 — the association's answers of 12 October on the import.
 |
 |   - housing gains «أخرى», which their own survey has and this did not
 |   - «تصنيف الحالة» is its own filterable field, not a zakat channel
 |   - the old researcher's review is an archive, because the file itself
 |     re-enters as a draft and goes through this system's chain
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->family = familyOf($this->region, adults: 1, children: 1);
});

it('accepts the housing type their survey has and this one lacked', function () {
    expect(__('sanabel.housing_type'))->toHaveKey('other');

    $this->family->housing()->first()->update(['housing_type' => 'other', 'type_other_ar' => 'غرفة على السطح']);

    expect($this->family->housing()->first()->housing_type)->toBe('other');
});

it('leaves the computed need alone for that type, which is why it is safe to import into', function () {
    $housing = $this->family->housing()->first();
    $engine = app(NeedEngine::class);

    $housing->update(['housing_type' => 'owned']);
    $owned = $engine->compute($this->family->fresh());

    $housing->update(['housing_type' => 'other', 'type_other_ar' => 'غرفة على السطح']);
    $other = $engine->compute($this->family->fresh());

    // No rent reference and no shelter adjustment, same as owned.
    expect($other['monthly_need'])->toBe($owned['monthly_need'])
        ->and($other['snapshot']['is_renting'])->toBeFalse()
        ->and($other['snapshot']['rent_reference']['amount'])->toBe(0)
        ->and($other['snapshot']['adjustments_total'])->toBe($owned['snapshot']['adjustments_total']);
});

it('keeps the survey classification apart from the zakat channel', function () {
    // فقير and مسكين are two of the eight channels; مرض and أرمل are not
    // channels at all, so one field cannot answer both questions.
    $this->family->update(['case_classification' => 'illness', 'zakat_category' => 'poor']);

    $case = $this->family->fresh();

    expect($case->case_classification)->toBe('illness')
        ->and($case->zakat_category)->toBe('poor')
        ->and(Beneficiary::CASE_CLASSIFICATIONS)->toContain('illness', 'widowed', 'debtor')
        ->and(Beneficiary::ZAKAT_CATEGORIES)->not->toContain('illness');
});

it('names every classification value in Arabic', function () {
    foreach (Beneficiary::CASE_CLASSIFICATIONS as $key) {
        expect(__('sanabel.case_classification.'.$key))->not->toBe('sanabel.case_classification.'.$key);
    }
});

it('holds the old researcher review as an archive the engine never reads', function () {
    $before = app(NeedEngine::class)->compute($this->family);

    $this->family->update(['legacy_review_json' => [
        'رأي الباحث' => 'الأسرة بحاجة ماسة',
        'التوصية' => 'قبول',
        'اسم الباحث' => 'أبو محمد',
        'رقم الاستمارة' => 'A-1042',
        'رابط PDF للأضبارة' => 'https://example.test/dossier.pdf',
        'تاريخ الفحص' => '2026-02-11',
    ]]);

    $after = app(NeedEngine::class)->compute($this->family->fresh());

    $figures = fn (array $n) => array_intersect_key($n, array_flip(['monthly_need', 'stable_income', 'gap', 'f']));

    expect($this->family->fresh()->legacy_review_json['التوصية'])->toBe('قبول')
        ->and($figures($after))->toBe($figures($before));
});

it('lets no change request rewrite the old system archive', function () {
    // It is a record of what the old system said. Editing it would make it a
    // second, unaccountable opinion on the file.
    expect(ChangeRequest::CHANGEABLE_FIELDS)->not->toContain('legacy_review_json')
        ->and(ChangeRequest::CHANGEABLE_FIELDS)->toContain('case_classification');

    expect(fn () => app(CaseService::class)->requestChange(
        $this->family,
        userWithRole('case_officer', ['region_id' => $this->region->id]),
        ['legacy_review_json' => ['رأي الباحث' => 'مزور']],
        'سبب',
    ))->toThrow(RuntimeException::class);
});

it('keeps an imported file away from the donors until this system approves it', function () {
    // They confirmed on 12 October that a migrated file enters as a draft and
    // walks the same chain. A draft is not published, so no donor sees it.
    expect($this->family->status)->toBe('draft')
        ->and(Beneficiary::published()->whereKey($this->family->getKey())->exists())->toBeFalse();
});
