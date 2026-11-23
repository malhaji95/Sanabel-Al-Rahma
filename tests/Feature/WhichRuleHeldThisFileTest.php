<?php

use App\Models\Setting;
use App\Services\DuplicateService;

/*
 | T-321 — phase two, item 13: «قواعد مخاطر قابلة للضبط, تُحال إلى المراجعة
 | ولا ترفض وحدها».
 |
 | Two of the three were already true: a rule that fires holds the file for
 | review, and nothing in the system rejects a family by itself.
 |
 | The third was not. The two checks were written into the method, so the
 | association could not switch one off; and the flag they raise is a yes or a
 | no, so whoever opens a held file has to guess which check fired and go
 | looking for the other file themselves.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    $this->duplicates = app(DuplicateService::class);
});

it('names the rule that held the file, not merely that one did', function () {
    $first = familyOf($this->region);
    $first->update(['phone_encrypted' => '0987654321']);

    $second = familyOf($this->region);
    $second->update(['phone_encrypted' => '0987654321']);

    expect($this->duplicates->flagIfSuspicious($second->refresh()))->toBe(['same_phone_region']);

    $second->refresh();

    expect($second->duplicate_review_flag)->toBeTrue()
        ->and($second->risk_reasons_json)->toBe(['same_phone_region'])
        // And the reason has Arabic for the screen to print.
        ->and(__('sanabel.risk_reason.same_phone_region'))->not->toBe('sanabel.risk_reason.same_phone_region');
});

it('names both when both fire', function () {
    $first = familyOf($this->region);
    $first->update(['phone_encrypted' => '0987654321', 'wallet_encrypted' => '9876543210']);

    $second = familyOf($this->region);
    $second->update(['phone_encrypted' => '0987654321', 'wallet_encrypted' => '9876543210']);

    expect($this->duplicates->flagIfSuspicious($second->refresh()))
        ->toEqualCanonicalizing(['same_wallet', 'same_phone_region']);
});

it('obeys the switch the association holds', function () {
    $first = familyOf($this->region);
    $first->update(['wallet_encrypted' => '9876543210']);

    $second = familyOf($this->region);
    $second->update(['wallet_encrypted' => '9876543210']);

    // Both rules start on, which is how they have always behaved.
    expect(DuplicateService::riskRules())->toBe([
        'same_wallet' => true,
        'same_phone_region' => true,
    ]);

    expect($this->duplicates->flagIfSuspicious($second->refresh()))->toBe(['same_wallet']);

    // Switched off, the same two files raise nothing.
    Setting::updateOrCreate(['key' => 'risk_rule_same_wallet'], ['value_json' => false]);

    $third = familyOf($this->region);
    $third->update(['wallet_encrypted' => '9876543210']);

    expect($this->duplicates->flagIfSuspicious($third->refresh()))->toBe([])
        ->and($third->refresh()->duplicate_review_flag)->toBeFalse()
        ->and($third->risk_reasons_json)->toBeNull();
});

it('never rejects a file, and never changes its status', function () {
    /*
     | «ولا ترفض وحدها». A rule is a reason to look, not a verdict: it writes
     | the flag and the reason, and a human decides.
     */
    $first = familyOf($this->region);
    $first->update(['phone_encrypted' => '0987654321']);

    $second = familyOf($this->region);
    $second->update(['phone_encrypted' => '0987654321']);

    $statusBefore = $second->refresh()->status;

    $this->duplicates->flagIfSuspicious($second);

    $second->refresh();

    expect($second->status)->toBe($statusBefore)
        ->and($second->reject_reason_ar)->toBeNull()
        ->and($second->merged_into_id)->toBeNull()
        ->and($second->deleted_at)->toBeNull();
});

it('holds nothing when the files have nothing in common', function () {
    familyOf($this->region)->update(['phone_encrypted' => '0911111111']);

    $other = familyOf($this->region);
    $other->update(['phone_encrypted' => '0922222222']);

    expect($this->duplicates->flagIfSuspicious($other->refresh()))->toBe([])
        ->and($other->refresh()->duplicate_review_flag)->toBeFalse();
});

it('does not hold a file on an empty wallet or an empty phone', function () {
    /*
     | Two files with no wallet at all are not two files sharing a wallet, and
     | the first version of a rule like this is exactly where that goes wrong.
     */
    $first = familyOf($this->region);
    $first->update(['phone_encrypted' => null, 'wallet_encrypted' => null]);

    $second = familyOf($this->region);
    $second->update(['phone_encrypted' => null, 'wallet_encrypted' => null]);

    expect($this->duplicates->flagIfSuspicious($second->refresh()))->toBe([]);
});

it('puts both switches on the settings screen', function () {
    $defaults = config('sanabel.setting_defaults');

    expect($defaults['risk_rule_same_wallet'])->toBeTrue()
        ->and($defaults['risk_rule_same_phone_region'])->toBeTrue()
        // Boolean defaults are what the settings form draws as toggles.
        ->and(__('sanabel.settings.keys.risk_rule_same_wallet'))->not->toBe('sanabel.settings.keys.risk_rule_same_wallet')
        ->and(__('sanabel.settings.keys.risk_rule_same_phone_region'))->not->toBe('sanabel.settings.keys.risk_rule_same_phone_region');
});
