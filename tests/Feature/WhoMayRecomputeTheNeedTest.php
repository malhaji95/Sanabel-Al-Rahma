<?php

use App\Filament\Resources\BeneficiaryResource\Pages\EditBeneficiary;
use App\Filament\Resources\BeneficiaryResource\Pages\ViewBeneficiary;
use App\Models\Assessment;
use Filament\Facades\Filament;

/*
 | T-133 — «ضمن الحاجة الشهرية المحتسبة ونسبة التغطية وهي الامور كيف يتم
 | حسابها, وفي حال تعديل البيانات والضغط على حساب تقييم جديد كيف تتم العملية
 | وهل في اي مشكلة عليها؟», 27 October.
 |
 | There was one. The assessment this button writes arrives `approved` and
 | supersedes the one before it, and the button asked nobody whether the
 | account pressing it may approve an assessment at all.
 */

beforeEach(function () {
    seedCore();
    $this->region = regionWithRates();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    passTwoFactor();
});

it('offers the button to the accounts that may approve an assessment', function () {
    /*
     | On the read-only screen, because that is the screen an approver can
     | open: the executive director holds `view_full_case` and not
     | `edit_draft` — they decide about families, they do not type the data
     | in. Before this task every decision button lived on the edit screen
     | alone, so the account that approves a file could not reach the approve
     | button at all, and only the chairman of the board, who holds every
     | key, could sign one.
     */
    $case = familyOf($this->region);

    Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('assess');
})->group('AUTH-01');

it('puts the whole decision on the screen the approver can open', function () {
    $case = familyOf($this->region);
    $case->forceFill(['status' => 'pending_approval'])->save();

    $page = Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertSuccessful();

    $page->assertActionVisible('approve')
        ->assertActionVisible('reject')
        ->assertActionVisible('returnToField');
})->group('AUTH-01');

it('leaves the same buttons where the editing roles already found them', function () {
    $case = familyOf($this->region);
    $case->forceFill(['status' => 'verified'])->save();

    Livewire\Livewire::actingAs(userWithRole('area_supervisor', ['region_id' => $this->region->id]))
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('returnToField');
});

it('refuses it to an account that enters files but approves nothing', function () {
    // A delegate edits their own draft and signs the field step. An approved
    // assessment is a different signature.
    $delegate = userWithRole('delegate', ['region_id' => placeIn($this->region, 'village')->id]);

    $case = familyOf($this->region);
    $case->forceFill(['created_by' => $delegate->id])->save();

    expect($delegate->can('create', Assessment::class))->toBeFalse();

    Livewire\Livewire::actingAs($delegate)
        ->test(EditBeneficiary::class, ['record' => $case->getRouteKey()])
        ->assertActionHidden('assess');
})->group('AUTH-02');

it('refuses it to the technical account, which decides nothing', function () {
    // T-105 — the administrator prepares the system and signs no judgement.
    expect(userWithRole('admin')->can('create', Assessment::class))->toBeFalse();
})->group('AUTH-02');

it('refuses it to a read-only council seat', function () {
    expect(userWithRole('council')->can('create', Assessment::class))->toBeFalse();
})->group('AUTH-02');

it('supersedes the previous assessment instead of deleting it, and freezes what it used', function () {
    $case = publishedCase($this->region);

    $first = $case->assessments()->where('status', 'approved')->firstOrFail();

    Livewire\Livewire::actingAs(userWithRole('executive_director'))
        ->test(ViewBeneficiary::class, ['record' => $case->getRouteKey()])
        ->callAction('assess');

    $second = $case->assessments()->where('status', 'approved')->firstOrFail();

    expect($second->id)->not->toBe($first->id)
        // Rule 3 — nothing is deleted; the old one is marked and kept.
        ->and($first->refresh()->status)->toBe('superseded')
        // Rule 8 — the reference values it used are frozen into the row.
        ->and($second->snapshot_json)->not->toBeEmpty()
        ->and($second->monthly_need)->toBeGreaterThan(0)
        // T-125's clock: the next review is scheduled from this computation.
        ->and($case->refresh()->next_assessment_due_at)->not->toBeNull();
});

it('says what the button does before it is pressed', function () {
    expect(__('sanabel.actions.recompute_help'))
        ->toContain('القيم المرجعية')
        ->toContain('مستبدلًا');
});
