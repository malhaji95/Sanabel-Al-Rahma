<?php

use App\Models\Setting;

/*
 | T-169 — «بالنسبة وضع فرش المنزل نحتاجه بهذي الطريقة مع اظهاره بالصفحة
 | الرئيسية: ١ الانطباع العام عن المنزل, ٢ نواقص احتياجات المنزل», 31 October,
 | with both lists written out by hand.
 |
 | T-164 had turned «وضع فرش المنزل» into one grade so it could be shown to a
 | donor at all. This is what the association actually wanted in its place:
 | a judgement of the house, and a list of what is missing from it. The second
 | is the half a donor can act on — «برّاد، أغطية» says what to give, where a
 | grade only says how bad it is.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
});

/** A family whose house has been described. */
function houseDescribed($case, array $housing)
{
    $case->housing()->updateOrCreate(['beneficiary_id' => $case->id], $housing);

    return $case->fresh();
}

it('publishes the impression the researcher picked', function () {
    $case = publishedCase($this->region);
    houseDescribed($case, ['home_impression' => 'almost_none']);

    expect(maskedOf($case)['home_impression_label'])->toBe('شبه معدوم');

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.masked.home_impression'))
        ->assertSee('شبه معدوم');
});

it('publishes what the house lacks, in the association’s own words', function () {
    $case = publishedCase($this->region);
    houseDescribed($case, ['missing_items' => ['fridge', 'blankets', 'gas']]);

    // Read in the association's own order, not the order they were ticked in.
    expect(maskedOf($case)['missing_items'])->toBe(['برّاد', 'غاز', 'أغطية']);

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertSee(__('sanabel.masked.missing_items'))
        ->assertSee('برّاد')
        ->assertSee('أغطية');
});

it('carries the word «غيرها» and never what was typed beside it', function () {
    $case = publishedCase($this->region);
    houseDescribed($case, [
        'missing_items' => ['other'],
        'missing_items_other_ar' => 'طقم كنب من بيت الجيران أبو خالد',
    ]);

    $masked = maskedOf($case);

    expect($masked['missing_items'])->toBe(['غيرها'])
        ->and(json_encode($masked, JSON_UNESCAPED_UNICODE))->not->toContain('أبو خالد');

    $this->get(route('opportunity', $case->file_number))
        ->assertOk()
        ->assertDontSee('أبو خالد');
});

it('ignores anything that is not on the association’s list', function () {
    $case = publishedCase($this->region);
    houseDescribed($case, [
        'home_impression' => 'a_word_nobody_added',
        'missing_items' => ['fridge', 'a_thing_nobody_added'],
    ]);

    expect(maskedOf($case)['home_impression_label'])->toBeNull()
        ->and(maskedOf($case)['missing_items'])->toBe(['برّاد']);
});

it('closes both with the switch that closes the house', function () {
    $case = publishedCase($this->region);
    houseDescribed($case, ['home_impression' => 'good', 'missing_items' => ['fridge']]);

    Setting::put('show_housing_detail', false);

    expect(maskedOf($case)['home_impression_label'])->toBeNull()
        ->and(maskedOf($case)['missing_items'])->toBe([]);
});

it('keeps the researcher’s own sentence about the furnishing off the page', function () {
    $case = publishedCase($this->region);
    $case->forceFill(['furnishing_ar' => 'فرش من عند الجيران وبرّاد معطّل'])->save();

    expect(json_encode(maskedOf($case), JSON_UNESCAPED_UNICODE))->not->toContain('عند الجيران');
});
